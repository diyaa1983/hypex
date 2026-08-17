import 'package:flutter/material.dart';

import '../core/theme.dart';

/// مربع تحذير/خطأ في وسط الشاشة.
Future<void> showCenterAlert(
  BuildContext context,
  String message, {
  String title = 'تنبيه',
  IconData icon = Icons.error_outline_rounded,
}) async {
  await showDialog<void>(
    context: context,
    barrierDismissible: true,
    builder: (ctx) => AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Row(
        children: [
          Icon(icon, color: AppTheme.danger, size: 26),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              title,
              style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
            ),
          ),
        ],
      ),
      content: Text(
        message,
        style: const TextStyle(fontSize: 14.5, height: 1.45),
      ),
      actions: [
        FilledButton(
          onPressed: () => Navigator.pop(ctx),
          child: const Text('حسناً'),
        ),
      ],
    ),
  );
}
