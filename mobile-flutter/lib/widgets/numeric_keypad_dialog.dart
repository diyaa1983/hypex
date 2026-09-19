import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/theme.dart';

/// لوحة أرقام مدمجة — بدون كيبورد النظام.
Future<String?> showNumericKeypadDialog(
  BuildContext context, {
  required String title,
  required String initial,
  bool decimal = false,
  double? maxValue,
}) {
  FocusManager.instance.primaryFocus?.unfocus();
  SystemChannels.textInput.invokeMethod('TextInput.hide');
  return showDialog<String>(
    context: context,
    barrierDismissible: true,
    builder: (ctx) => _NumericKeypadDialog(
      title: title,
      initial: initial,
      decimal: decimal,
      maxValue: maxValue,
    ),
  );
}

class _NumericKeypadDialog extends StatefulWidget {
  const _NumericKeypadDialog({
    required this.title,
    required this.initial,
    required this.decimal,
    this.maxValue,
  });

  final String title;
  final String initial;
  final bool decimal;
  final double? maxValue;

  @override
  State<_NumericKeypadDialog> createState() => _NumericKeypadDialogState();
}

class _NumericKeypadDialogState extends State<_NumericKeypadDialog> {
  late String _value;
  bool _replaceOnDigit = true;

  @override
  void initState() {
    super.initState();
    _value = widget.initial.trim();
  }

  void _pushDigit(String d) {
    setState(() {
      if (_replaceOnDigit) {
        _value = d;
        _replaceOnDigit = false;
        return;
      }
      if (_value == '0' && d != '.') {
        _value = d;
        return;
      }
      _value += d;
    });
  }

  void _pushDot() {
    if (!widget.decimal) return;
    setState(() {
      if (_replaceOnDigit) {
        _value = '0.';
        _replaceOnDigit = false;
        return;
      }
      if (_value.contains('.')) return;
      _value = _value.isEmpty ? '0.' : '$_value.';
    });
  }

  void _backspace() {
    setState(() {
      _replaceOnDigit = false;
      if (_value.isEmpty) return;
      _value = _value.substring(0, _value.length - 1);
    });
  }

  void _clear() {
    setState(() {
      _value = '';
      _replaceOnDigit = false;
    });
  }

  void _confirm() {
    var raw = _value.trim();
    if (raw.isEmpty || raw == '.') raw = '0';
    if (raw.endsWith('.')) raw = raw.substring(0, raw.length - 1);
    final n = double.tryParse(raw.replaceAll(',', ''));
    if (n != null && widget.maxValue != null && n > widget.maxValue!) {
      raw = widget.maxValue!.toStringAsFixed(
        widget.maxValue! == widget.maxValue!.roundToDouble() ? 0 : 2,
      );
    }
    Navigator.pop(context, raw);
  }

  Widget _key(
    String label, {
    required VoidCallback onTap,
    Color? color,
    Color? fg,
    IconData? icon,
  }) {
    return Padding(
      padding: const EdgeInsets.all(3),
      child: Material(
        color: color ?? const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(8),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(8),
          child: SizedBox(
            height: 44,
            child: Center(
              child: icon != null
                  ? Icon(icon, size: 22, color: fg ?? AppTheme.textMain)
                  : Text(
                      label,
                      style: TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                        color: fg ?? AppTheme.textMain,
                      ),
                    ),
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      insetPadding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 300),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                widget.title,
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 8),
              Container(
                width: double.infinity,
                height: 44,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: const Color(0xFFF8FAFC),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AppTheme.border),
                ),
                child: Text(
                  _value.isEmpty ? '0' : _value,
                  textDirection: TextDirection.ltr,
                  style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.w900,
                    letterSpacing: 0.4,
                  ),
                ),
              ),
              const SizedBox(height: 8),
              Table(
                children: [
                  TableRow(children: [
                    _key('1', onTap: () => _pushDigit('1')),
                    _key('2', onTap: () => _pushDigit('2')),
                    _key('3', onTap: () => _pushDigit('3')),
                  ]),
                  TableRow(children: [
                    _key('4', onTap: () => _pushDigit('4')),
                    _key('5', onTap: () => _pushDigit('5')),
                    _key('6', onTap: () => _pushDigit('6')),
                  ]),
                  TableRow(children: [
                    _key('7', onTap: () => _pushDigit('7')),
                    _key('8', onTap: () => _pushDigit('8')),
                    _key('9', onTap: () => _pushDigit('9')),
                  ]),
                  TableRow(children: [
                    widget.decimal
                        ? _key('.', onTap: _pushDot)
                        : _key(
                            'C',
                            onTap: _clear,
                            color: const Color(0xFFFFE4E6),
                            fg: const Color(0xFFB91C1C),
                          ),
                    _key('0', onTap: () => _pushDigit('0')),
                    _key(
                      '',
                      onTap: _backspace,
                      icon: Icons.backspace_outlined,
                    ),
                  ]),
                ],
              ),
              if (widget.decimal) ...[
                const SizedBox(height: 2),
                SizedBox(
                  width: double.infinity,
                  height: 36,
                  child: TextButton(
                    onPressed: _clear,
                    child: const Text(
                      'مسح',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 4),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => Navigator.pop(context),
                      style: OutlinedButton.styleFrom(
                        minimumSize: const Size(0, 40),
                        visualDensity: VisualDensity.compact,
                      ),
                      child: const Text(
                        'إلغاء',
                        style: TextStyle(fontWeight: FontWeight.w800),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: FilledButton(
                      onPressed: _confirm,
                      style: FilledButton.styleFrom(
                        backgroundColor: AppTheme.primary,
                        minimumSize: const Size(0, 40),
                        visualDensity: VisualDensity.compact,
                      ),
                      child: const Text(
                        'تم',
                        style: TextStyle(fontWeight: FontWeight.w800),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
