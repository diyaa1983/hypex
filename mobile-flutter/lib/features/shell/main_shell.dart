import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/session.dart';
import '../home/home_screen.dart';
import '../invoices/invoice_list_screen.dart';
import '../receipts/receipt_list_screen.dart';
import '../settings/settings_screen.dart';

class _Tab {
  const _Tab(this.label, this.icon, this.activeIcon, this.page);
  final String label;
  final IconData icon;
  final IconData activeIcon;
  final Widget page;
}

/// الهيكل الرئيسي: شريط تنقّل سفلي مع الحفاظ على حالة كل تبويب.
class MainShell extends StatefulWidget {
  const MainShell({super.key});

  @override
  State<MainShell> createState() => _MainShellState();
}

class _MainShellState extends State<MainShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<SessionController>();
    final tabs = <_Tab>[
      const _Tab(
        'الرئيسية',
        Icons.grid_view_outlined,
        Icons.grid_view_rounded,
        HomeScreen(),
      ),
      if (s.can('m_sales_invoice_list'))
        const _Tab(
          'الفواتير',
          Icons.receipt_long_outlined,
          Icons.receipt_long_rounded,
          InvoiceListScreen(embedded: true),
        ),
      if (s.can('m_receipt_list'))
        const _Tab(
          'السندات',
          Icons.account_balance_wallet_outlined,
          Icons.account_balance_wallet_rounded,
          ReceiptListScreen(embedded: true),
        ),
      const _Tab(
        'الإعدادات',
        Icons.settings_outlined,
        Icons.settings_rounded,
        SettingsScreen(),
      ),
    ];

    final index = _index.clamp(0, tabs.length - 1);

    return Scaffold(
      body: IndexedStack(
        index: index,
        children: tabs.map((t) => t.page).toList(),
      ),
      bottomNavigationBar: Container(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: Color(0xFFE3E8EF))),
        ),
        child: NavigationBar(
          selectedIndex: index,
          onDestinationSelected: (i) => setState(() => _index = i),
          destinations: [
            for (final t in tabs)
              NavigationDestination(
                icon: Icon(t.icon),
                selectedIcon: Icon(t.activeIcon),
                label: t.label,
              ),
          ],
        ),
      ),
    );
  }
}
