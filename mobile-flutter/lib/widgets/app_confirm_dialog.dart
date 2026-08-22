import 'package:flutter/material.dart';

import '../core/theme.dart';

/// أزرار تأكيد متساوية 50/50.
Future<bool?> showAppConfirmDialog(
  BuildContext context, {
  required String title,
  required String message,
  String cancelLabel = 'إلغاء',
  String confirmLabel = 'تأكيد',
  bool destructive = false,
}) {
  return showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
      content: Text(message, style: const TextStyle(height: 1.45)),
      contentPadding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
      actionsPadding: EdgeInsets.zero,
      actions: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 14),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    minimumSize: const Size(0, 46),
                    textStyle: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                    ),
                    side: const BorderSide(color: AppTheme.border),
                  ),
                  onPressed: () => Navigator.pop(ctx, false),
                  child: Text(cancelLabel),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: FilledButton(
                  style: FilledButton.styleFrom(
                    backgroundColor:
                        destructive ? AppTheme.danger : AppTheme.primary,
                    minimumSize: const Size(0, 46),
                    textStyle: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  onPressed: () => Navigator.pop(ctx, true),
                  child: Text(confirmLabel),
                ),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

/// حوار «خروج يدوي» — سبب + أزرار إلغاء/متابعة متساوية.
Future<String?> showManualCheckoutReasonDialog(
  BuildContext context, {
  String defaultReason = 'نسي الخروج بـ GPS من موقع العميل',
}) {
  return showDialog<String>(
    context: context,
    builder: (ctx) {
      final ctrl = TextEditingController(text: defaultReason);
      return AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('خروج يدوي', style: TextStyle(fontWeight: FontWeight.w800)),
        content: TextField(
          controller: ctrl,
          maxLines: 3,
          decoration: const InputDecoration(
            labelText: 'السبب',
            hintText: 'لماذا الخروج يدوياً؟',
          ),
        ),
        contentPadding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
        actionsPadding: EdgeInsets.zero,
        actions: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 14),
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    style: OutlinedButton.styleFrom(
                      minimumSize: const Size(0, 46),
                      textStyle: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                      side: const BorderSide(color: AppTheme.border),
                    ),
                    onPressed: () => Navigator.pop(ctx),
                    child: const Text('إلغاء'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: FilledButton(
                    style: FilledButton.styleFrom(
                      minimumSize: const Size(0, 46),
                      textStyle: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    onPressed: () => Navigator.pop(ctx, ctrl.text.trim()),
                    child: const Text('متابعة'),
                  ),
                ),
              ],
            ),
          ),
        ],
      );
    },
  );
}
