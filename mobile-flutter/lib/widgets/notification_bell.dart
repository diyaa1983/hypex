import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/inbox_controller.dart';
import '../core/theme.dart';

/// جرس الإشعارات — شارة عند وجود رسائل غير مقروءة (اعتماد موقع العميل…).
class NotificationBellButton extends StatelessWidget {
  const NotificationBellButton({super.key});

  static String _when(String raw) {
    final v = raw.trim();
    if (v.length >= 16) return v.substring(0, 16).replaceFirst('T', ' ');
    return v;
  }

  static Future<void> openInbox(BuildContext context) async {
    final inbox = context.read<InboxController>();
    await inbox.refresh();
    if (!context.mounted) return;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (ctx) {
        final h = MediaQuery.sizeOf(ctx).height;
        return SafeArea(
          child: SizedBox(
            height: h * 0.58,
            child: Consumer<InboxController>(
              builder: (ctx, box, _) {
                return Column(
                  children: [
                    const SizedBox(height: 10),
                    Container(
                      width: 42,
                      height: 4,
                      decoration: BoxDecoration(
                        color: const Color(0xFFD0D7E2),
                        borderRadius: BorderRadius.circular(99),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 14, 8, 8),
                      child: Row(
                        children: [
                          const Icon(Icons.notifications_rounded,
                              color: AppTheme.primary),
                          const SizedBox(width: 8),
                          const Expanded(
                            child: Text(
                              'الإشعارات',
                              style: TextStyle(
                                fontWeight: FontWeight.w900,
                                fontSize: 17,
                              ),
                            ),
                          ),
                          if (box.unreadCount > 0)
                            TextButton(
                              onPressed: box.markAllRead,
                              child: const Text('قراءة الكل'),
                            ),
                        ],
                      ),
                    ),
                    const Divider(height: 1),
                    Expanded(
                      child: box.items.isEmpty
                          ? const Center(
                              child: Padding(
                                padding: EdgeInsets.all(24),
                                child: Column(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Icon(
                                      Icons.notifications_none_rounded,
                                      size: 48,
                                      color: Color(0xFF9AA8BC),
                                    ),
                                    SizedBox(height: 12),
                                    Text(
                                      'لا توجد إشعارات حالياً',
                                      textAlign: TextAlign.center,
                                      style: TextStyle(
                                        fontWeight: FontWeight.w800,
                                        fontSize: 15,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            )
                          : ListView.separated(
                              padding: const EdgeInsets.fromLTRB(12, 8, 12, 16),
                              itemCount: box.items.length,
                              separatorBuilder: (_, __) =>
                                  const SizedBox(height: 8),
                              itemBuilder: (_, i) {
                                final it = box.items[i];
                                final approved = it.isApproved;
                                final color = approved
                                    ? AppTheme.success
                                    : (it.isRejected
                                        ? AppTheme.danger
                                        : AppTheme.primary);
                                return Material(
                                  color: it.isRead
                                      ? Colors.white
                                      : color.withValues(alpha: 0.08),
                                  borderRadius: BorderRadius.circular(14),
                                  child: Padding(
                                    padding: const EdgeInsets.fromLTRB(
                                        12, 12, 12, 12),
                                    child: Row(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Icon(
                                          approved
                                              ? Icons.check_circle_rounded
                                              : (it.isRejected
                                                  ? Icons.cancel_rounded
                                                  : Icons
                                                      .notifications_rounded),
                                          color: color,
                                          size: 26,
                                        ),
                                        const SizedBox(width: 10),
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment:
                                                CrossAxisAlignment.start,
                                            children: [
                                              Text(
                                                it.title,
                                                style: const TextStyle(
                                                  fontWeight: FontWeight.w800,
                                                  fontSize: 14.5,
                                                ),
                                              ),
                                              const SizedBox(height: 4),
                                              Text(
                                                it.body,
                                                style: const TextStyle(
                                                  fontSize: 13,
                                                  height: 1.35,
                                                  color: AppTheme.textSoft,
                                                ),
                                              ),
                                              if (it.createdAt.isNotEmpty) ...[
                                                const SizedBox(height: 6),
                                                Text(
                                                  _when(it.createdAt),
                                                  style: const TextStyle(
                                                    fontSize: 11.5,
                                                    color: Color(0xFF9AA8BC),
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
                              },
                            ),
                    ),
                  ],
                );
              },
            ),
          ),
        );
      },
    );
    if (context.mounted && inbox.unreadCount > 0) {
      await inbox.markAllRead();
    }
  }

  @override
  Widget build(BuildContext context) {
    final unread = context.select<InboxController, int>((b) => b.unreadCount);
    return IconButton(
      tooltip: unread > 0 ? 'الإشعارات ($unread)' : 'الإشعارات',
      onPressed: () => openInbox(context),
      padding: const EdgeInsets.symmetric(horizontal: 6),
      visualDensity: VisualDensity.compact,
      icon: Badge(
        isLabelVisible: unread > 0,
        backgroundColor: AppTheme.danger,
        textColor: Colors.white,
        smallSize: 10,
        label: Text(
          unread > 99 ? '99+' : '$unread',
          style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800),
        ),
        child: const Icon(
          Icons.notifications_rounded,
          color: Colors.white,
          size: 26,
        ),
      ),
    );
  }
}
