import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import 'core/session.dart';
import 'core/theme.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/server_setup_screen.dart';
import 'features/customers/customer_add_screen.dart';
import 'features/gps/invoice_gps_screen.dart';
import 'features/gps/user_gps_screen.dart';
import 'features/gps/user_gps_tracker_screen.dart';
import 'features/gps/user_gps_route_screen.dart';
import 'features/invoices/invoice_form_screen.dart';
import 'features/invoices/invoice_list_screen.dart';
import 'features/invoices/invoice_view_screen.dart';
import 'features/party/party_statement_screen.dart';
import 'features/receipts/receipt_form_screen.dart';
import 'features/receipts/receipt_list_screen.dart';
import 'features/rep/rep_custody_list_screen.dart';
import 'features/rep/rep_stock_screen.dart';
import 'features/rep/rep_transfer_screen.dart';
import 'features/returns/return_form_screen.dart';
import 'features/returns/return_list_screen.dart';
import 'features/returns/return_view_screen.dart';
import 'features/settings/settings_screen.dart';
import 'features/shell/main_shell.dart';

class NammaApp extends StatelessWidget {
  const NammaApp({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.read<SessionController>();
    final router = _buildRouter(session);

    return MaterialApp.router(
      title: 'النماء',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.build(),
      routerConfig: router,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: child ?? const SizedBox.shrink(),
      ),
    );
  }

  GoRouter _buildRouter(SessionController session) {
    return GoRouter(
      refreshListenable: session,
      initialLocation: '/home',
      redirect: (context, state) {
        if (session.booting) return null;
        final loc = state.matchedLocation;
        final onAuth = loc == '/server' || loc == '/login';
        if (!session.authenticated) {
          return onAuth ? null : '/login';
        }
        if (onAuth) return '/home';
        return null;
      },
      routes: [
        GoRoute(
          path: '/server',
          builder: (_, __) => const ServerSetupScreen(),
        ),
        GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
        GoRoute(path: '/home', builder: (_, __) => const MainShell()),
        GoRoute(path: '/settings', builder: (_, __) => const SettingsScreen()),
        GoRoute(
          path: '/invoices',
          builder: (_, __) => const InvoiceListScreen(),
        ),
        GoRoute(
          path: '/invoices/new',
          builder: (_, __) => const InvoiceFormScreen(),
        ),
        GoRoute(
          path: '/customers/new',
          builder: (_, __) => const CustomerAddScreen(),
        ),
        GoRoute(
          path: '/invoices/:id/edit',
          builder: (_, s) => InvoiceFormScreen(
            invoiceId: int.parse(s.pathParameters['id']!),
          ),
        ),
        GoRoute(
          path: '/invoices/:id',
          builder: (_, s) =>
              InvoiceViewScreen(invoiceId: int.parse(s.pathParameters['id']!)),
        ),
        GoRoute(
          path: '/receipts',
          builder: (_, __) => const ReceiptListScreen(),
        ),
        GoRoute(
          path: '/receipts/new',
          builder: (_, __) => const ReceiptFormScreen(),
        ),
        GoRoute(path: '/returns', builder: (_, __) => const ReturnListScreen()),
        GoRoute(
          path: '/returns/new',
          builder: (_, __) => const ReturnFormScreen(),
        ),
        GoRoute(
          path: '/returns/:id/edit',
          builder: (_, s) => ReturnFormScreen(
            returnId: int.parse(s.pathParameters['id']!),
          ),
        ),
        GoRoute(
          path: '/returns/:id',
          builder: (_, s) =>
              ReturnViewScreen(returnId: int.parse(s.pathParameters['id']!)),
        ),
        GoRoute(
          path: '/statement',
          builder: (_, __) => const PartyStatementScreen(),
        ),
        GoRoute(
          path: '/rep/load',
          builder: (_, __) => const RepTransferScreen(direction: 'load'),
        ),
        GoRoute(
          path: '/rep/return',
          builder: (_, __) => const RepTransferScreen(direction: 'return'),
        ),
        GoRoute(
          path: '/rep/custody',
          builder: (_, __) => const RepCustodyListScreen(),
        ),
        GoRoute(path: '/rep/stock', builder: (_, __) => const RepStockScreen()),
        GoRoute(
          path: '/gps/invoices',
          builder: (_, __) => const InvoiceGpsScreen(),
        ),
        GoRoute(
          path: '/gps/users',
          builder: (_, __) => const UserGpsScreen(),
        ),
        GoRoute(
          path: '/gps/tracker',
          builder: (_, __) => const UserGpsTrackerScreen(),
        ),
        GoRoute(
          path: '/gps/route',
          builder: (_, __) => const UserGpsRouteScreen(),
        ),
      ],
    );
  }
}
