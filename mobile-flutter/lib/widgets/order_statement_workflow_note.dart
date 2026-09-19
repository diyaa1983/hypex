import 'package:flutter/material.dart';

import '../core/theme.dart';

/// توضيح أن طلب الشراء لا يُعرض في كشف الحساب.
class OrderStatementWorkflowNote extends StatelessWidget {
  const OrderStatementWorkflowNote({super.key, this.compact = false});

  final bool compact;

  static const String fullText =
      'طلب الشراء لا يظهر في كشف الحساب. '
      'الكشف يعرض حركات الحساب (فواتير، سندات، مرتجعات…) فقط.';

  static const String compactText =
      'طلب الشراء لا يظهر في كشف الحساب.';

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(compact ? 10 : 12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppTheme.border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.info_outline_rounded,
            size: compact ? 18 : 20,
            color: AppTheme.primary,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              compact ? compactText : fullText,
              style: TextStyle(
                fontSize: compact ? 11.5 : 12,
                fontWeight: FontWeight.w600,
                color: AppTheme.textSoft,
                height: 1.45,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
