import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../offline/offline_controller.dart';
import '../../offline/offline_store.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';
import '../customer_orders/customer_order_form_screen.dart';
import '../party/party_statement_screen.dart';

/// تبويبات الزيارة بعد تسجيل الدخول: طلب شراء، معلومات، سجل، كشف حساب.
class VisitWorkspacePanel extends StatefulWidget {
  const VisitWorkspacePanel({
    super.key,
    required this.customerId,
    required this.customerName,
    required this.customerCode,
    required this.visitRouteLineId,
    required this.visitOpen,
    this.orderId,
    this.onOrderChanged,
  });

  final int customerId;
  final String customerName;
  final String customerCode;
  final int visitRouteLineId;
  final bool visitOpen;
  final int? orderId;
  final VoidCallback? onOrderChanged;

  @override
  State<VisitWorkspacePanel> createState() => _VisitWorkspacePanelState();
}

class _VisitWorkspacePanelState extends State<VisitWorkspacePanel> {
  Map<String, dynamic>? _customer;
  bool _infoLoading = true;
  String? _infoError;

  List<Map<String, dynamic>> _orders = [];
  bool _ordersLoading = false;
  String? _ordersError;
  DateTime _ordersFrom = DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _ordersTo = DateTime.now();

  List<Map<String, dynamic>> _invoices = [];
  bool _invoicesLoading = false;
  String? _invoicesError;
  DateTime _invoicesFrom =
      DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _invoicesTo = DateTime.now();

  int? _orderId;

  @override
  void initState() {
    super.initState();
    _orderId = widget.orderId;
    _loadCustomer();
    _loadOrders();
    _loadInvoices();
  }

  @override
  void didUpdateWidget(covariant VisitWorkspacePanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.customerId != widget.customerId) {
      _orderId = widget.orderId;
      _loadCustomer();
      _loadOrders();
      _loadInvoices();
    } else if (oldWidget.orderId != widget.orderId) {
      _orderId = widget.orderId;
    }
  }

  String _iso(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _loadCustomer() async {
    setState(() {
      _infoLoading = true;
      _infoError = null;
    });
    final offline = context.read<OfflineController>();
    try {
      if (!offline.online && offline.catalogReady) {
        final local =
            await OfflineStore.instance.getCustomerById(widget.customerId);
        if (!mounted) return;
        setState(() {
          _customer = {
            'id': widget.customerId,
            'name': widget.customerName,
            'code': widget.customerCode,
            ...?local,
          };
          _infoLoading = false;
        });
        return;
      }
      final res = await context.read<ApiClient>().getJson(
            AppConfig.customerViewPath,
            query: {'id': widget.customerId},
          );
      if (!mounted) return;
      final c = (res['customer'] as Map?)?.cast<String, dynamic>();
      setState(() {
        _customer = {
          'id': widget.customerId,
          'name': widget.customerName,
          'code': widget.customerCode,
          ...?c,
        };
        _infoLoading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        final local =
            await OfflineStore.instance.getCustomerById(widget.customerId);
        if (local != null && mounted) {
          setState(() {
            _customer = {
              'id': widget.customerId,
              'name': widget.customerName,
              'code': widget.customerCode,
              ...local,
            };
            _infoLoading = false;
          });
          return;
        }
      }
      setState(() {
        _infoError = e.message;
        _infoLoading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _infoError = e.toString();
        _infoLoading = false;
      });
    }
  }

  Future<void> _loadOrders() async {
    setState(() {
      _ordersLoading = true;
      _ordersError = null;
    });
    final offline = context.read<OfflineController>();
    if (!offline.online) {
      if (!mounted) return;
      setState(() {
        _orders = [];
        _ordersLoading = false;
        _ordersError = 'سجل الطلبات يتطلب اتصالاً — متاح بعد عودة الإنترنت.';
      });
      return;
    }
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.customerOrderListPath,
        query: {
          'customer_id': widget.customerId,
          'from': _iso(_ordersFrom),
          'to': _iso(_ordersTo),
          'page': 1,
        },
      );
      if (!mounted) return;
      setState(() {
        _orders = (res['orders'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _ordersLoading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _ordersError = e.message;
        _ordersLoading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _ordersError = e.toString();
        _ordersLoading = false;
      });
    }
  }

  Future<void> _loadInvoices() async {
    setState(() {
      _invoicesLoading = true;
      _invoicesError = null;
    });
    final offline = context.read<OfflineController>();
    if (!offline.online) {
      if (!mounted) return;
      setState(() {
        _invoices = [];
        _invoicesLoading = false;
        _invoicesError = 'سجل الفواتير يتطلب اتصالاً — متاح بعد عودة الإنترنت.';
      });
      return;
    }
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.salesInvoiceListPath,
        query: {
          'customer_id': widget.customerId,
          'from': _iso(_invoicesFrom),
          'to': _iso(_invoicesTo),
          'filter': 'all',
          'page': 1,
        },
      );
      if (!mounted) return;
      setState(() {
        _invoices = (res['invoices'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _invoicesLoading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _invoicesError = e.message;
        _invoicesLoading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _invoicesError = e.toString();
        _invoicesLoading = false;
      });
    }
  }

  Future<void> _pickDate({
    required bool isFrom,
    required DateTime current,
    required ValueChanged<DateTime> apply,
  }) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: current,
      firstDate: DateTime(2015),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    apply(picked);
  }

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: Colors.white,
      child: DefaultTabController(
        length: 5,
        child: Column(
          children: [
            Material(
              color: Colors.white,
              child: TabBar(
                isScrollable: true,
                tabAlignment: TabAlignment.start,
                labelColor: AppTheme.primary,
                unselectedLabelColor: AppTheme.textSoft,
                indicatorColor: AppTheme.primary,
                tabs: const [
                  Tab(text: 'طلب شراء'),
                  Tab(text: 'معلومات العميل'),
                  Tab(text: 'الطلبات التاريخية'),
                  Tab(text: 'الفواتير التاريخية'),
                  Tab(text: 'كشف حساب'),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: TabBarView(
                children: [
                  _buildOrderTab(),
                  _buildInfoTab(),
                  _buildHistTab(
                    loading: _ordersLoading,
                    error: _ordersError,
                    from: _ordersFrom,
                    to: _ordersTo,
                    onFrom: () => _pickDate(
                      isFrom: true,
                      current: _ordersFrom,
                      apply: (d) {
                        setState(() => _ordersFrom = d);
                        _loadOrders();
                      },
                    ),
                    onTo: () => _pickDate(
                      isFrom: false,
                      current: _ordersTo,
                      apply: (d) {
                        setState(() => _ordersTo = d);
                        _loadOrders();
                      },
                    ),
                    onRetry: _loadOrders,
                    child: _ordersList(),
                  ),
                  _buildHistTab(
                    loading: _invoicesLoading,
                    error: _invoicesError,
                    from: _invoicesFrom,
                    to: _invoicesTo,
                    onFrom: () => _pickDate(
                      isFrom: true,
                      current: _invoicesFrom,
                      apply: (d) {
                        setState(() => _invoicesFrom = d);
                        _loadInvoices();
                      },
                    ),
                    onTo: () => _pickDate(
                      isFrom: false,
                      current: _invoicesTo,
                      apply: (d) {
                        setState(() => _invoicesTo = d);
                        _loadInvoices();
                      },
                    ),
                    onRetry: _loadInvoices,
                    child: _invoicesList(),
                  ),
                  PartyStatementScreen(
                    key: ValueKey('stmt-${widget.customerId}'),
                    initialCustomerId: widget.customerId,
                    initialCustomerName: widget.customerName,
                    initialCustomerCode: widget.customerCode,
                    autoRun: true,
                    embedded: true,
                    hidePartyPicker: true,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildOrderTab() {
    final canOrder = widget.visitOpen && widget.visitRouteLineId != 0;
    if (!canOrder) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'سجّل الدخول عند العميل أولاً لإنشاء طلب شراء.',
            textAlign: TextAlign.center,
            style: TextStyle(
              color: AppTheme.textSoft,
              fontWeight: FontWeight.w700,
              fontSize: 14.5,
            ),
          ),
        ),
      );
    }
    return CustomerOrderFormScreen(
      key: ValueKey(
        'po-${widget.customerId}-${widget.visitRouteLineId}-${_orderId ?? 0}',
      ),
      embedded: true,
      hideCustomerPicker: true,
      initialCustomerId: widget.customerId,
      initialCustomerName: widget.customerName,
      initialCustomerCode: widget.customerCode,
      visitRouteLineId: widget.visitRouteLineId,
      orderId: (_orderId ?? 0) > 0 ? _orderId : null,
      onSaved: (id) {
        setState(() => _orderId = id);
        widget.onOrderChanged?.call();
      },
      onDeleted: () {
        setState(() => _orderId = null);
        widget.onOrderChanged?.call();
      },
    );
  }

  Widget _buildInfoTab() {
    return AsyncView(
      loading: _infoLoading,
      error: _infoError,
      onRetry: _loadCustomer,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
        children: [
          Text(
            widget.customerName,
            style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
          ),
          if (widget.customerCode.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              widget.customerCode,
              style: const TextStyle(color: AppTheme.textSoft, fontSize: 13.5),
            ),
          ],
          const SizedBox(height: 12),
          AppCard(
            child: Column(
              children: [
                InfoRow('الهاتف', Fmt.str(_customer?['phone']), ltr: true),
                InfoRow('الرقم الضريبي', Fmt.str(_customer?['tax_number'])),
                InfoRow('البريد', Fmt.str(_customer?['email']), ltr: true),
                InfoRow(
                  'العنوان',
                  Fmt.str(_customer?['address']).isEmpty
                      ? Fmt.str(_customer?['address_ar'])
                      : Fmt.str(_customer?['address']),
                ),
                InfoRow(
                  'فترة السداد',
                  Fmt.toInt(_customer?['payment_period']) > 0
                      ? '${Fmt.toInt(_customer?['payment_period'])} يوم'
                      : '',
                ),
                InfoRow(
                  'الموقع',
                  (_customer?['latitude'] != null &&
                          _customer?['longitude'] != null)
                      ? '${Fmt.toDouble(_customer?['latitude']).toStringAsFixed(6)} ، ${Fmt.toDouble(_customer?['longitude']).toStringAsFixed(6)}'
                      : 'غير محدد',
                  ltr: true,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildHistTab({
    required bool loading,
    required String? error,
    required DateTime from,
    required DateTime to,
    required VoidCallback onFrom,
    required VoidCallback onTo,
    required VoidCallback onRetry,
    required Widget child,
  }) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: onFrom,
                  icon: const Icon(Icons.calendar_today, size: 16),
                  label: Text('من: ${Fmt.dmy(_iso(from))}'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: onTo,
                  icon: const Icon(Icons.calendar_today, size: 16),
                  label: Text('إلى: ${Fmt.dmy(_iso(to))}'),
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: AsyncView(
            loading: loading,
            error: error,
            onRetry: onRetry,
            child: child,
          ),
        ),
      ],
    );
  }

  Widget _ordersList() {
    if (_orders.isEmpty) {
      return ListView(
        children: const [
          SizedBox(height: 50),
          EmptyState(
            message: 'لا توجد طلبات في هذه الفترة.',
            icon: Icons.shopping_cart_outlined,
          ),
        ],
      );
    }
    return ListView.builder(
      padding: const EdgeInsets.all(12),
      itemCount: _orders.length,
      itemBuilder: (_, i) {
        final o = _orders[i];
        return AppCard(
          onTap: () => context.push('/customer-orders/${Fmt.toInt(o['id'])}'),
          child: Row(
            children: [
              const MiniIcon(
                Icons.shopping_cart_checkout_rounded,
                color: AppTheme.success,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  Fmt.dmy(Fmt.str(o['order_date'])),
                  style: const TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 14,
                  ),
                ),
              ),
              Text(
                Fmt.money(Fmt.toDouble(o['total'])),
                textDirection: TextDirection.ltr,
                style: const TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                ),
              ),
              const SizedBox(width: 6),
              const Icon(Icons.chevron_left_rounded, color: AppTheme.textSoft),
            ],
          ),
        );
      },
    );
  }

  Widget _invoicesList() {
    if (_invoices.isEmpty) {
      return ListView(
        children: const [
          SizedBox(height: 50),
          EmptyState(
            message: 'لا توجد فواتير في هذه الفترة.',
            icon: Icons.receipt_long_outlined,
          ),
        ],
      );
    }
    return ListView.builder(
      padding: const EdgeInsets.all(12),
      itemCount: _invoices.length,
      itemBuilder: (_, i) {
        final inv = _invoices[i];
        return AppCard(
          onTap: () => context.push('/invoices/${Fmt.toInt(inv['id'])}'),
          child: Row(
            children: [
              const MiniIcon(
                Icons.receipt_long_rounded,
                color: AppTheme.primarySoft,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      Fmt.str(inv['invoice_no']).isEmpty
                          ? '#${Fmt.toInt(inv['id'])}'
                          : Fmt.str(inv['invoice_no']),
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    Text(
                      Fmt.dmy(Fmt.str(inv['invoice_date'])),
                      style: const TextStyle(
                        color: AppTheme.textSoft,
                        fontSize: 12.5,
                      ),
                    ),
                  ],
                ),
              ),
              Text(
                Fmt.money(Fmt.toDouble(inv['total'])),
                textDirection: TextDirection.ltr,
                style: const TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                ),
              ),
              const SizedBox(width: 6),
              const Icon(Icons.chevron_left_rounded, color: AppTheme.textSoft),
            ],
          ),
        );
      },
    );
  }
}
