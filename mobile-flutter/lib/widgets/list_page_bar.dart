import 'package:flutter/material.dart';

import '../core/theme.dart';

/// شريط تنقّل بين صفحات القوائم حسب إعداد «أسطر الصفحة» في النظام.
class ListPageBar extends StatelessWidget {
  const ListPageBar({
    super.key,
    required this.page,
    required this.totalPages,
    required this.total,
    required this.perPage,
    required this.onPageChanged,
  });

  final int page;
  final int totalPages;
  final int total;
  final int perPage;
  final ValueChanged<int> onPageChanged;

  factory ListPageBar.fromPager(
    Map<String, dynamic>? pager, {
    required ValueChanged<int> onPageChanged,
  }) {
    final page = (pager?['page'] as num?)?.toInt() ?? 1;
    final totalPages = (pager?['total_pages'] as num?)?.toInt() ?? 1;
    final total = (pager?['total'] as num?)?.toInt() ?? 0;
    final perPage = (pager?['per_page'] as num?)?.toInt() ?? 10;
    return ListPageBar(
      page: page,
      totalPages: totalPages,
      total: total,
      perPage: perPage,
      onPageChanged: onPageChanged,
    );
  }

  @override
  Widget build(BuildContext context) {
    if (total < 1) return const SizedBox.shrink();

    final from = (page - 1) * perPage + 1;
    final to = page * perPage > total ? total : page * perPage;
    final canPrev = page > 1;
    final canNext = page < totalPages;

    return Material(
      color: AppTheme.surface,
      elevation: 2,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
          child: Row(
            children: [
              IconButton(
                tooltip: 'السابق',
                onPressed: canPrev ? () => onPageChanged(page - 1) : null,
                icon: const Icon(Icons.chevron_right_rounded),
              ),
              Expanded(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      'صفحة $page من $totalPages',
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 13,
                      ),
                    ),
                    Text(
                      '$from–$to من $total  ·  $perPage/صفحة',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontSize: 11,
                        color: Colors.grey.shade700,
                      ),
                    ),
                  ],
                ),
              ),
              IconButton(
                tooltip: 'التالي',
                onPressed: canNext ? () => onPageChanged(page + 1) : null,
                icon: const Icon(Icons.chevron_left_rounded),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
