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
