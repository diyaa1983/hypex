import 'package:flutter/material.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';

import '../core/theme.dart';
import '../services/bluetooth_print_service.dart';
import '../services/bluetooth_printer_settings.dart';
import 'async_view.dart';
import 'ui_kit.dart';

/// بطاقة إعدادات طابعة Bluetooth — تُعرض لكل المستخدمين من شاشة الإعدادات.
class BluetoothPrinterSettingsCard extends StatefulWidget {
  const BluetoothPrinterSettingsCard({super.key});

  @override
  State<BluetoothPrinterSettingsCard> createState() =>
      _BluetoothPrinterSettingsCardState();
}

class _BluetoothPrinterSettingsCardState
    extends State<BluetoothPrinterSettingsCard> {
  BluetoothPrinterConfig _cfg = const BluetoothPrinterConfig(mac: '', name: '');
  bool _loading = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    final cfg = await BluetoothPrinterSettings.load();
    if (!mounted) return;
    setState(() {
      _cfg = cfg;
      _loading = false;
    });
  }

  Future<void> _pickDevice() async {
    setState(() => _busy = true);
    try {
      final okPerm = await BluetoothPrintService.ensurePermissions();
      if (!mounted) return;
      if (!okPerm) {
        showSnack(context, 'يلزم منح أذونات البلوتوث.', error: true);
        return;
      }
      final devices = await BluetoothPrintService.pairedDevices();
      if (!mounted) return;
      if (devices.isEmpty) {
        final open = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('لا توجد أجهزة مقترنة'),
            content: const Text(
              'اربط الطابعة أولاً من إعدادات بلوتوث الأندرويد، ثم ارجع واخترها هنا.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('لاحقاً'),
              ),
              FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                child: const Text('فتح البلوتوث'),
              ),
            ],
          ),
        );
        if (open == true) {
          await BluetoothPrintService.openSystemBluetoothSettings();
        }
        return;
      }

      final selected = await showModalBottomSheet<BluetoothInfo>(
        context: context,
        showDragHandle: true,
        isScrollControlled: true,
        builder: (ctx) => SafeArea(
          child: Padding(
            padding: EdgeInsets.only(
              bottom: MediaQuery.viewInsetsOf(ctx).bottom,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Padding(
                  padding: EdgeInsets.fromLTRB(16, 4, 16, 8),
                  child: Text(
                    'اختر طابعة Bluetooth',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
                  ),
                ),
                ConstrainedBox(
                  constraints: BoxConstraints(
                    maxHeight: MediaQuery.sizeOf(ctx).height * 0.45,
                  ),
                  child: ListView.separated(
                    shrinkWrap: true,
                    itemCount: devices.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (_, i) {
                      final d = devices[i];
                      final isSel = d.macAdress == _cfg.mac;
                      return ListTile(
                        leading: Icon(
                          Icons.print_rounded,
                          color: isSel ? AppTheme.primary : AppTheme.textSoft,
                        ),
                        title: Text(
                          d.name.trim().isEmpty ? 'جهاز بدون اسم' : d.name,
                        ),
                        subtitle: Text(
                          d.macAdress,
                          textDirection: TextDirection.ltr,
                        ),
                        trailing: isSel
                            ? const Icon(
                                Icons.check_circle,
                                color: AppTheme.success,
                              )
                            : null,
                        onTap: () => Navigator.pop(ctx, d),
                      );
                    },
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.all(12),
                  child: OutlinedButton.icon(
                    onPressed: () async {
                      Navigator.pop(ctx);
                      await BluetoothPrintService.openSystemBluetoothSettings();
                    },
                    icon: const Icon(Icons.bluetooth_searching_rounded),
                    label: const Text('إضافة جهاز من إعدادات الأندرويد'),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
      if (selected == null || !mounted) return;
      final next = _cfg.copyWith(
        mac: selected.macAdress,
        name: selected.name.trim().isEmpty ? selected.macAdress : selected.name,
      );
      await BluetoothPrinterSettings.save(next);
      if (!mounted) return;
      setState(() => _cfg = next);
      showSnack(context, 'تم حفظ الطابعة: ${next.displayLabel}');
    } catch (e) {
      if (!mounted) return;
      showSnack(context, e.toString().replaceFirst('Bad state: ', ''), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _setPaper(int mm) async {
    final next = _cfg.copyWith(paperMm: mm);
    await BluetoothPrinterSettings.save(next);
    if (!mounted) return;
    setState(() => _cfg = next);
  }

  Future<void> _clear() async {
    await BluetoothPrinterSettings.clear();
    if (!mounted) return;
    setState(() => _cfg = const BluetoothPrinterConfig(mac: '', name: ''));
    showSnack(context, 'تم إلغاء الطابعة المحفوظة.');
  }

  Future<void> _test() async {
    setState(() => _busy = true);
    try {
      final err = await BluetoothPrintService.testPrint();
      if (!mounted) return;
      showSnack(
        context,
        err ?? 'تم إرسال صفحة اختبار للطابعة.',
        error: err != null,
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const AppCard(
        child: Center(
          child: Padding(
            padding: EdgeInsets.all(16),
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    }

    return AppCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          ListTile(
            leading: MiniIcon(
              Icons.print_rounded,
              color: _cfg.isConfigured ? AppTheme.success : AppTheme.primary,
            ),
            title: const Text(
              'طابعة Bluetooth',
              style: TextStyle(fontWeight: FontWeight.w800),
            ),
            subtitle: Text(
              _cfg.isConfigured
                  ? '${_cfg.displayLabel} • ${_cfg.paperMm} مم'
                  : 'غير محددة — ستُستخدم نافذة طباعة النظام',
              style: const TextStyle(fontSize: 12, color: AppTheme.textSoft),
            ),
          ),
          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
            child: Row(
              children: [
                const Text('عرض الورق:', style: TextStyle(fontSize: 13)),
                const SizedBox(width: 8),
                ChoiceChip(
                  label: const Text('58 مم'),
                  selected: _cfg.paperMm == 58,
                  onSelected: _busy ? null : (_) => _setPaper(58),
                ),
                const SizedBox(width: 6),
                ChoiceChip(
                  label: const Text('80 مم'),
                  selected: _cfg.paperMm == 80,
                  onSelected: _busy ? null : (_) => _setPaper(80),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 6, 12, 12),
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: FilledButton.icon(
                        onPressed: _busy ? null : _pickDevice,
                        icon: const Icon(Icons.bluetooth_searching_rounded),
                        label: Text(_cfg.isConfigured ? 'تغيير الطابعة' : 'اختيار طابعة'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: _busy
                            ? null
                            : () => BluetoothPrintService
                                .openSystemBluetoothSettings(),
                        icon: const Icon(Icons.settings_bluetooth_rounded),
                        label: const Text('ربط جهاز'),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: _busy || !_cfg.isConfigured ? null : _test,
                        icon: const Icon(Icons.print_outlined),
                        label: const Text('اختبار طباعة'),
                      ),
                    ),
                    if (_cfg.isConfigured) ...[
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextButton.icon(
                          onPressed: _busy ? null : _clear,
                          icon: const Icon(Icons.link_off_rounded),
                          label: const Text('إلغاء الحفظ'),
                        ),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 4),
                const Text(
                  'تُستخدم هذه الطابعة لجميع شاشات الطباعة في التطبيق.',
                  style: TextStyle(fontSize: 11.5, color: AppTheme.textSoft, height: 1.35),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
