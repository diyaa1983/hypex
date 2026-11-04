import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/payment_period.dart';
import '../../core/theme.dart';
import '../../offline/offline_controller.dart';
import '../../offline/offline_store.dart';
import '../../services/document_print_helper.dart';
import '../../services/report_table_pdf.dart';
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

class _VisitWorkspacePanelState extends State<VisitWorkspacePanel>
    with SingleTickerProviderStateMixin {
  Map<String, dynamic>? _customer;
  bool _infoLoading = true;
  String? _infoError;
  late final TabController _tabs;

  List<Map<String, dynamic>> _orders = [];
  bool _ordersLoading = false;
  String? _ordersError;
  DateTime _ordersFrom = DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _ordersTo = DateTime.now();
  bool _ordersPdfBusy = false;

  int? _orderId;
  List<Map<String, dynamic>> _unsentLocal = [];

  @override
  void initState() {
    super.initState();
    _orderId = (widget.orderId != null && widget.orderId != 0)
        ? widget.orderId
        : null;
    _tabs = TabController(length: 4, vsync: this);
    _tabs.addListener(() {
      if (!_tabs.indexIsChanging && mounted) setState(() {});
    });
    _loadCustomer();
    _loadOrders();
    // افتح آخر مسودة غير مرحّلة لاستكمال الترحيل/التعديل/الحذف.
    _loadUnsentLocal(autoSelectIfEmpty: true);
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant VisitWorkspacePanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.customerId != widget.customerId ||
        oldWidget.visitRouteLineId != widget.visitRouteLineId) {
      _orderId = null;
      _loadCustomer();
      _loadOrders();
      _loadUnsentLocal(autoSelectIfEmpty: true);
    } else if (oldWidget.orderId != widget.orderId &&
        widget.orderId != null &&
        widget.orderId != 0) {
      // يُحدَّث فقط عند حفظ مسودة حالية من الأب
      _orderId = widget.orderId;
    }
  }

  Future<void> _loadUnsentLocal({bool autoSelectIfEmpty = false}) async {
    final rows = await OfflineStore.instance.listUnsentOrdersForCustomer(
      widget.customerId,
    );
    if (!mounted) return;
    int? nextId = _orderId;
    if (autoSelectIfEmpty &&
        (nextId == null || nextId == 0) &&
        rows.isNotEmpty) {
      final visit = widget.visitRouteLineId;
      Map<String, dynamic>? match;
      if (visit != 0) {
        for (final o in rows) {
          final vid = (o['visit_route_line_id'] as num?)?.toInt() ??
              int.tryParse('${o['visit_route_line_id'] ?? ''}') ??
              0;
          if (vid == visit) {
            match = o;
            break;
          }
        }
      }
      match ??= rows.first;
      nextId = (match['id'] as num?)?.toInt() ??
          int.tryParse('${match['id'] ?? ''}');
    }
    setState(() {
      _unsentLocal = rows;
      _orderId = (nextId == null || nextId == 0) ? null : nextId;
    });
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
      child: Column(
          children: [
            Material(
              color: Colors.white,
              child: TabBar(
                controller: _tabs,
                isScrollable: false,
                labelPadding: EdgeInsets.zero,
                labelColor: AppTheme.primary,
                unselectedLabelColor: AppTheme.textSoft,
                indicatorColor: AppTheme.primary,
                labelStyle: const TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 12.5,
                ),
                unselectedLabelStyle: const TextStyle(
                  fontWeight: FontWeight.w600,
                  fontSize: 12.5,
                ),
                tabs: const [
                  Tab(height: 40, text: 'طلب شراء'),
                  Tab(height: 40, text: 'معلومات العميل'),
                  Tab(height: 40, text: 'طلبات العملاء'),
                  Tab(height: 40, text: 'كشف حساب'),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: IndexedStack(
                index: _tabs.index,
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
    Widget draftLeading() {
      return Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 0; i < _unsentLocal.length; i++) ...[
            Builder(
              builder: (_) {
                final o = _unsentLocal[i];
                final raw = Fmt.str(o['order_no']).trim();
                final isOff = raw.toUpperCase().startsWith('OFF-');
                final label = raw.isEmpty || isOff
                    ? (_unsentLocal.length == 1 ? 'مسودة' : 'مسودة ${i + 1}')
                    : raw;
                final id = (o['id'] as num?)?.toInt() ?? 0;
                final selected = _orderId == id;
                return Padding(
                  padding: const EdgeInsetsDirectional.only(end: 4),
                  child: SizedBox(
                    height: 32,
                    child: OutlinedButton(
                      onPressed: id == 0
                          ? null
                          : () => setState(() => _orderId = id),
                      style: OutlinedButton.styleFrom(
                        backgroundColor: selected
                            ? AppTheme.primary.withValues(alpha: 0.12)
                            : Colors.white,
                        foregroundColor: selected
                            ? AppTheme.primary
                            : AppTheme.textMain,
                        side: BorderSide(
                          color: selected
                              ? AppTheme.primary
                              : AppTheme.border,
                        ),
                        padding: const EdgeInsets.symmetric(horizontal: 8),
                        minimumSize: const Size(0, 32),
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ),
                      child: Text(
                        label,
                        style: const TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                  ),
                );
              },
            ),
          ],
          SizedBox(
            height: 32,
            child: OutlinedButton.icon(
              onPressed: () => setState(() => _orderId = null),
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                minimumSize: const Size(0, 32),
                tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              ),
              icon: const Icon(Icons.add_rounded, size: 15),
              label: const Text(
                'طلب جديد',
                style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w800),
              ),
            ),
          ),
        ],
      );
    }

    return CustomerOrderFormScreen(
      key: ValueKey(
        'po-${widget.customerId}-${widget.visitRouteLineId}-${_orderId ?? 0}',
      ),
      embedded: true,
      hideCustomerPicker: true,
      toolbarLeading: draftLeading(),
      initialCustomerId: widget.customerId,
      initialCustomerName: widget.customerName,
      initialCustomerCode: widget.customerCode,
      visitRouteLineId: widget.visitRouteLineId,
      orderId: (_orderId ?? 0) != 0 ? _orderId : null,
      onSaved: (id) {
        setState(() => _orderId = id == 0 ? null : id);
        _loadUnsentLocal();
        widget.onOrderChanged?.call();
      },
      onPosted: () {
        setState(() => _orderId = null);
        _loadUnsentLocal();
        widget.onOrderChanged?.call();
      },
      onDeleted: () {
        setState(() => _orderId = null);
        _loadUnsentLocal();
        widget.onOrderChanged?.call();
      },
    );
  }

  Widget _buildInfoTab() {
    return AsyncView(
      loading: _infoLoading,
      error: _infoError,
      onRetry: _loadCustomer,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              widget.customerName,
              style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
            ),
            if (widget.customerCode.isNotEmpty)
              Text(
                widget.customerCode,
                style: const TextStyle(color: AppTheme.textSoft, fontSize: 12.5),
              ),
            const SizedBox(height: 8),
            Expanded(
              child: AppCard(
                child: Column(
                  children: [
                    Expanded(
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Column(
                              children: [
                                InfoRow(
                                  'الهاتف',
                                  Fmt.str(_customer?['phone']),
                                  ltr: true,
                                ),
                                InfoRow(
                                  'الرقم الضريبي',
                                  Fmt.str(_customer?['tax_number']),
                                ),
                                InfoRow(
                                  'البريد',
                                  Fmt.str(_customer?['email']),
                                  ltr: true,
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 16),
                          Expanded(
                            child: Column(
                              children: [
                                InfoRow(
                                  'العنوان',
                                  Fmt.str(_customer?['address']).isEmpty
                                      ? Fmt.str(_customer?['address_ar'])
                                      : Fmt.str(_customer?['address']),
                                ),
                                InfoRow(
                                  'فترة السداد',
                                  () {
                                    final label =
                                        Fmt.str(_customer?['payment_period_label']);
                                    if (label.isNotEmpty) return label;
                                    return PaymentPeriod.displayOf(
                                      _customer?['payment_period'],
                                    );
                                  }(),
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
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
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

  String _orderNo(Map<String, dynamic> o) {
    final raw = Fmt.str(o['order_no']).trim();
    return raw.isEmpty ? '#${Fmt.toInt(o['id'])}' : raw;
  }

  Future<void> _openOrdersPdf() async {
    if (_orders.isEmpty || _ordersPdfBusy) return;
    setState(() => _ordersPdfBusy = true);
    try {
      final bytes = await ReportTablePdf.build(
        title: 'طلبات العملاء',
        subtitle: widget.customerName,
        headers: const [
          '#',
          'رقم الطلب',
          'التاريخ',
          'الحالة',
          'العميل',
          'اسم المندوب',
          'المجموع',
        ],
        rows: [
          for (var i = 0; i < _orders.length; i++)
            [
              '${i + 1}',
              _orderNo(_orders[i]),
              Fmt.dmy(Fmt.str(_orders[i]['order_date'])),
              _histOrderStatusLabel(_orders[i]),
              Fmt.str(_orders[i]['customer_name']).isEmpty
                  ? '—'
                  : Fmt.str(_orders[i]['customer_name']),
              Fmt.str(_orders[i]['sales_rep_name']).isEmpty
                  ? '—'
                  : Fmt.str(_orders[i]['sales_rep_name']),
              Fmt.money(Fmt.toDouble(_orders[i]['total'])),
            ],
        ],
        landscape: true,
      );
      if (!mounted) return;
      await DocumentPrintHelper.openPdfBytes(
        context,
        bytes: bytes,
        title: 'طلبات العملاء',
        fileName: 'طلبات-العملاء',
      );
    } catch (e) {
      if (mounted) showSnack(context, 'تعذر إنشاء PDF: $e', error: true);
    } finally {
      if (mounted) setState(() => _ordersPdfBusy = false);
    }
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
    return ListView(
      padding: const EdgeInsets.fromLTRB(8, 10, 8, 16),
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton.icon(
            onPressed: _ordersPdfBusy ? null : _openOrdersPdf,
            icon: _ordersPdfBusy
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.picture_as_pdf_outlined, size: 18),
            label: const Text('PDF'),
          ),
        ),
        const SizedBox(height: 8),
        LinedReportTable(
          headers: const [
            '#',
            'رقم الطلبية',
            'تاريخ الطلبية',
            'الحالة',
            'اسم العميل',
            'اسم المندوب',
            'مجموع الطلبية',
          ],
          numericCols: const {0, 6},
          onRowTap: (i) =>
              context.push('/customer-orders/${Fmt.toInt(_orders[i]['id'])}'),
          rows: [
            for (var i = 0; i < _orders.length; i++)
              [
                '${i + 1}',
                Fmt.str(_orders[i]['order_no']).isEmpty
                    ? '#${Fmt.toInt(_orders[i]['id'])}'
                    : Fmt.str(_orders[i]['order_no']),
                Fmt.dmy(Fmt.str(_orders[i]['order_date'])),
                _histOrderStatusLabel(_orders[i]),
                Fmt.str(_orders[i]['customer_name']).isEmpty
                    ? '—'
                    : Fmt.str(_orders[i]['customer_name']),
                Fmt.str(_orders[i]['sales_rep_name']).isEmpty
                    ? '—'
                    : Fmt.str(_orders[i]['sales_rep_name']),
                Fmt.money(Fmt.toDouble(_orders[i]['total'])),
              ],
          ],
        ),
      ],
    );
  }

  static String _histOrderStatusLabel(Map<String, dynamic> o) {
    final status = Fmt.str(o['status']).toLowerCase();
    if (status == 'approved') return 'معتمد';
    if (status == 'draft') {
      final sent = o['is_sent'] == true ||
          o['is_sent'] == 1 ||
          '${o['is_sent']}' == '1';
      return sent ? 'مسودة · مرحّل' : 'مسودة';
    }
    final label = Fmt.str(o['status_label']);
    return label.isEmpty ? (status.isEmpty ? '—' : status) : label;
  }
}
