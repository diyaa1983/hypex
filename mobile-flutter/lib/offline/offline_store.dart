import 'dart:convert';

import 'package:sqflite/sqflite.dart';

import 'offline_db.dart';

class OfflineSyncInfo {
  const OfflineSyncInfo({
    required this.hasData,
    this.syncedAt,
    this.customers = 0,
    this.items = 0,
    this.warehouses = 0,
    this.stockRows = 0,
    this.pendingOutbox = 0,
    this.errorOutbox = 0,
    this.noOrderReasons = 0,
    this.visitRadiusM = 200,
    this.routeDays = 0,
    this.visitReportRows = 0,
    this.ordersPending = 0,
    this.ordersSent = 0,
    this.oracleStatements = 0,
    this.cacheFrom,
    this.cacheTo,
  });

  final bool hasData;
  final String? syncedAt;
  final int customers;
  final int items;
  final int warehouses;
  final int stockRows;
  /// عمليات بانتظار الترحيل فعلياً (status=pending فقط).
  final int pendingOutbox;
  /// عمليات فشلت سابقاً — لا تُحسب كـ«معلّقة» في الشريط.
  final int errorOutbox;
  final int noOrderReasons;
  final int visitRadiusM;
  final int routeDays;
  final int visitReportRows;
  final int ordersPending;
  final int ordersSent;
  final int oracleStatements;
  final String? cacheFrom;
  final String? cacheTo;

  int get flushableOutbox => pendingOutbox + errorOutbox;
}

class OfflineStore {
  OfflineStore._();
  static final OfflineStore instance = OfflineStore._();

  Future<Database> get _db => OfflineDb.instance();

  Future<void> setMeta(String key, String value) async {
    final db = await _db;
    await db.insert(
      'sync_meta',
      {'key': key, 'value': value},
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<String?> getMeta(String key) async {
    final db = await _db;
    final rows = await db.query(
      'sync_meta',
      where: 'key = ?',
      whereArgs: [key],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return rows.first['value'] as String?;
  }

  Future<bool> get hasCatalog async {
    final at = await getMeta('synced_at');
    return at != null && at.isNotEmpty;
  }

  Future<OfflineSyncInfo> syncInfo() async {
    final db = await _db;
    final syncedAt = await getMeta('synced_at');
    final customers =
        Sqflite.firstIntValue(await db.rawQuery('SELECT COUNT(*) FROM customers')) ??
            0;
    final items =
        Sqflite.firstIntValue(await db.rawQuery('SELECT COUNT(*) FROM items')) ??
            0;
    final warehouses = Sqflite.firstIntValue(
          await db.rawQuery('SELECT COUNT(*) FROM warehouses'),
        ) ??
        0;
    final stockRows =
        Sqflite.firstIntValue(await db.rawQuery('SELECT COUNT(*) FROM stock')) ??
            0;
    final pending = Sqflite.firstIntValue(
          await db.rawQuery(
            "SELECT COUNT(*) FROM outbox WHERE status = 'pending'",
          ),
        ) ??
        0;
    final errored = Sqflite.firstIntValue(
          await db.rawQuery(
            "SELECT COUNT(*) FROM outbox WHERE status = 'error'",
          ),
        ) ??
        0;
    var reasons = 0;
    try {
      reasons = Sqflite.firstIntValue(
            await db.rawQuery('SELECT COUNT(*) FROM no_order_reasons'),
          ) ??
          0;
    } catch (_) {
      reasons = 0;
    }
    var routeDays = 0;
    var visitReportRows = 0;
    var ordersPending = 0;
    var ordersSent = 0;
    var oracleStatements = 0;
    try {
      routeDays = Sqflite.firstIntValue(
            await db.rawQuery(
              "SELECT COALESCE(SUM(json_array_length(days_json)), 0) FROM route_months",
            ),
          ) ??
          0;
    } catch (_) {
      try {
        final months = await db.query('route_months');
        for (final m in months) {
          final days = jsonDecode(m['days_json'] as String);
          if (days is List) routeDays += days.length;
        }
      } catch (_) {
        routeDays = 0;
      }
    }
    try {
      visitReportRows = Sqflite.firstIntValue(
            await db.rawQuery('SELECT COUNT(*) FROM visit_report_rows'),
          ) ??
          0;
    } catch (_) {
      visitReportRows = 0;
    }
    try {
      ordersPending = Sqflite.firstIntValue(
            await db.rawQuery('SELECT COUNT(*) FROM orders WHERE is_sent = 0'),
          ) ??
          0;
      ordersSent = Sqflite.firstIntValue(
            await db.rawQuery('SELECT COUNT(*) FROM orders WHERE is_sent = 1'),
          ) ??
          0;
    } catch (_) {
      ordersPending = 0;
      ordersSent = 0;
    }
    try {
      oracleStatements = Sqflite.firstIntValue(
            await db.rawQuery('SELECT COUNT(*) FROM oracle_statements'),
          ) ??
          0;
    } catch (_) {
      oracleStatements = 0;
    }
    final radius = await visitRadiusM();
    return OfflineSyncInfo(
      hasData: syncedAt != null && syncedAt.isNotEmpty,
      syncedAt: syncedAt,
      customers: customers,
      items: items,
      warehouses: warehouses,
      stockRows: stockRows,
      pendingOutbox: pending,
      errorOutbox: errored,
      noOrderReasons: reasons,
      visitRadiusM: radius,
      routeDays: routeDays,
      visitReportRows: visitReportRows,
      ordersPending: ordersPending,
      ordersSent: ordersSent,
      oracleStatements: oracleStatements,
      cacheFrom: await getMeta('cache_from'),
      cacheTo: await getMeta('cache_to'),
    );
  }

  Future<void> _ensureV3(DatabaseExecutor txn) async {
    await txn.execute('''
      CREATE TABLE IF NOT EXISTS route_months (
        month_ym TEXT PRIMARY KEY NOT NULL,
        date_from TEXT NOT NULL,
        date_to TEXT NOT NULL,
        days_json TEXT NOT NULL
      )
    ''');
    await txn.execute('''
      CREATE TABLE IF NOT EXISTS route_day_visits (
        route_date TEXT PRIMARY KEY NOT NULL,
        weekday_label TEXT NOT NULL DEFAULT '',
        visits_json TEXT NOT NULL
      )
    ''');
    await txn.execute('''
      CREATE TABLE IF NOT EXISTS visit_report_rows (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        route_date TEXT NOT NULL,
        customer_id INTEGER NOT NULL,
        payload_json TEXT NOT NULL
      )
    ''');
    await txn.execute('''
      CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY NOT NULL,
        order_no TEXT NOT NULL DEFAULT '',
        order_date TEXT NOT NULL DEFAULT '',
        customer_id INTEGER NOT NULL DEFAULT 0,
        customer_name TEXT NOT NULL DEFAULT '',
        warehouse_id INTEGER NOT NULL DEFAULT 0,
        warehouse_name TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft',
        is_sent INTEGER NOT NULL DEFAULT 0,
        total REAL NOT NULL DEFAULT 0,
        line_count INTEGER NOT NULL DEFAULT 0,
        total_qty REAL NOT NULL DEFAULT 0,
        client_uuid TEXT,
        payload_json TEXT NOT NULL DEFAULT '{}'
      )
    ''');
    await txn.execute('''
      CREATE TABLE IF NOT EXISTS oracle_statements (
        customer_id INTEGER NOT NULL,
        from_date TEXT NOT NULL,
        to_date TEXT NOT NULL,
        party_name TEXT NOT NULL DEFAULT '',
        cached_at TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        PRIMARY KEY (customer_id, from_date, to_date)
      )
    ''');
  }

  Future<void> replaceCatalog(Map<String, dynamic> payload) async {
    final db = await _db;
    final customers = (payload['customers'] as List? ?? []).whereType<Map>();
    final warehouses = (payload['warehouses'] as List? ?? []).whereType<Map>();
    final taxRates = (payload['tax_rates'] as List? ?? []).whereType<Map>();
    final items = (payload['items'] as List? ?? []).whereType<Map>();
    final stock = (payload['stock'] as List? ?? []).whereType<Map>();
    final meta = (payload['meta'] as Map?)?.cast<String, dynamic>() ?? {};

    await db.transaction((txn) async {
      await _ensureV3(txn);

      final customersDelta = payload['customers_delta'] == true;
      final removedIds = (payload['customers_removed'] as List? ?? [])
          .map((e) => (e as num?)?.toInt() ?? 0)
          .where((e) => e > 0)
          .toSet();

      Map<String, Object?> custRow(Map r) => {
            'id': (r['id'] as num?)?.toInt() ?? 0,
            'name': (r['name'] ?? '').toString(),
            'code': (r['code'] ?? '').toString(),
            'phone': (r['phone'] ?? '').toString(),
            'address': (r['address'] ?? '').toString(),
            'latitude': r['latitude'],
            'longitude': r['longitude'],
            'payment_period': (r['payment_period'] as num?)?.toInt() ?? 0,
            'use_wholesale_price':
                (r['use_wholesale_price'] as num?)?.toInt() ?? 0,
          };

      if (customersDelta) {
        final custBatch = txn.batch();
        for (final r in customers) {
          custBatch.insert(
            'customers',
            custRow(r),
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
        await custBatch.commit(noResult: true);
        for (final id in removedIds) {
          await txn.delete('customers', where: 'id = ?', whereArgs: [id]);
        }
      } else {
        // احتفظ بالعملاء المحليين المؤقتين (id سالب) حتى الترحيل
        final localCustomers = await txn.query(
          'customers',
          where: 'id < 0',
        );
        await txn.delete('customers');
        final custBatch = txn.batch();
        for (final r in customers) {
          custBatch.insert('customers', custRow(r));
        }
        for (final r in localCustomers) {
          custBatch.insert(
            'customers',
            r,
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
        await custBatch.commit(noResult: true);
      }

      final scopeComplete = payload['customers_scope_complete'] == true;
      final scopeIds = (payload['customer_ids'] as List? ?? [])
          .map((e) => (e as num?)?.toInt() ?? 0)
          .where((e) => e > 0)
          .toSet();
      if (scopeComplete) {
        final locals = await txn.query(
          'customers',
          columns: ['id'],
          where: 'id > 0',
        );
        for (final row in locals) {
          final id = (row['id'] as num?)?.toInt() ?? 0;
          if (id > 0 && !scopeIds.contains(id)) {
            await txn.delete('customers', where: 'id = ?', whereArgs: [id]);
          }
        }
      }

      await txn.delete('warehouses');
      await txn.delete('tax_rates');
      await txn.delete('items');
      await txn.delete('stock');

      final whBatch = txn.batch();
      for (final r in warehouses) {
        whBatch.insert('warehouses', {
          'id': (r['id'] as num?)?.toInt() ?? 0,
          'name': (r['name'] ?? '').toString(),
        });
      }
      await whBatch.commit(noResult: true);

      final taxBatch = txn.batch();
      for (final r in taxRates) {
        taxBatch.insert('tax_rates', {
          'id': (r['id'] as num?)?.toInt() ?? 0,
          'name': (r['name'] ?? '').toString(),
          'rate_percent': (r['rate_percent'] as num?)?.toDouble() ?? 0,
        });
      }
      await taxBatch.commit(noResult: true);

      final itemBatch = txn.batch();
      for (final r in items) {
        itemBatch.insert('items', {
          'id': (r['id'] as num?)?.toInt() ?? 0,
          'name': (r['name'] ?? '').toString(),
          'sku': (r['sku'] ?? '').toString(),
          'barcode': (r['barcode'] ?? '').toString(),
          'sale_price': (r['sale_price'] as num?)?.toDouble() ?? 0,
          'wholesale_price': (r['wholesale_price'] as num?)?.toDouble() ?? 0,
          'units_json': jsonEncode(r['units'] ?? []),
        });
      }
      await itemBatch.commit(noResult: true);

      final stkBatch = txn.batch();
      for (final r in stock) {
        stkBatch.insert('stock', {
          'warehouse_id': (r['warehouse_id'] as num?)?.toInt() ?? 0,
          'item_id': (r['item_id'] as num?)?.toInt() ?? 0,
          'qty': (r['qty'] as num?)?.toDouble() ?? 0,
        });
      }
      await stkBatch.commit(noResult: true);

      try {
        await txn.execute('''
          CREATE TABLE IF NOT EXISTS no_order_reasons (
            id INTEGER PRIMARY KEY NOT NULL,
            name_ar TEXT NOT NULL
          )
        ''');
        await txn.delete('no_order_reasons');
        final reasonBatch = txn.batch();
        final reasons =
            (payload['no_order_reasons'] as List? ?? []).whereType<Map>();
        if (reasons.isEmpty) {
          reasonBatch.insert('no_order_reasons', {
            'id': -1,
            'name_ar': 'لا يحتاج طلبية حالياً',
          });
          reasonBatch.insert('no_order_reasons', {
            'id': -2,
            'name_ar': 'العميل مغلق',
          });
          reasonBatch.insert('no_order_reasons', {
            'id': -3,
            'name_ar': 'أخرى',
          });
        } else {
          for (final r in reasons) {
            reasonBatch.insert('no_order_reasons', {
              'id': (r['id'] as num?)?.toInt() ?? 0,
              'name_ar': (r['name_ar'] ?? r['name'] ?? '').toString(),
            });
          }
        }
        await reasonBatch.commit(noResult: true);
      } catch (_) {}

      // —— جولات ——
      await txn.delete('route_months');
      await txn.delete('route_day_visits');
      final months =
          (payload['route_months'] as List? ?? []).whereType<Map>();
      final monthBatch = txn.batch();
      for (final m in months) {
        final ym = (m['month'] ?? '').toString();
        if (ym.isEmpty) continue;
        monthBatch.insert('route_months', {
          'month_ym': ym,
          'date_from': (m['date_from'] ?? '').toString(),
          'date_to': (m['date_to'] ?? '').toString(),
          'days_json': jsonEncode(m['days'] ?? []),
        });
        final days = (m['days'] as List? ?? []).whereType<Map>();
        for (final d in days) {
          final date = (d['route_date'] ?? '').toString();
          if (date.isEmpty) continue;
          final customersDay =
              (d['customers'] as List? ?? d['visits'] as List? ?? []);
          monthBatch.insert(
            'route_day_visits',
            {
              'route_date': date,
              'weekday_label':
                  (d['weekday_label'] ?? '').toString(),
              'visits_json': jsonEncode(customersDay),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      }
      await monthBatch.commit(noResult: true);

      final todayVisits =
          (payload['today_visits'] as List? ?? []).whereType<Map>().where((v) {
        final p = v['in_plan'];
        return p == true || p == 1 || p == '1';
      }).toList();
      if (todayVisits.isNotEmpty) {
        final today = DateTime.now();
        final todayIso =
            '${today.year.toString().padLeft(4, '0')}-${today.month.toString().padLeft(2, '0')}-${today.day.toString().padLeft(2, '0')}';
        await txn.insert(
          'route_day_visits',
          {
            'route_date': todayIso,
            'weekday_label': '',
            'visits_json': jsonEncode(todayVisits),
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }

      // —— تقرير الزيارات ——
      await txn.delete('visit_report_rows');
      final report = (payload['visit_report'] as Map?)?.cast<String, dynamic>() ??
          {};
      final reportVisits =
          (report['visits'] as List? ?? []).whereType<Map>();
      final reportBatch = txn.batch();
      for (final r in reportVisits) {
        reportBatch.insert('visit_report_rows', {
          'route_date': (r['route_date'] ?? '').toString(),
          'customer_id': (r['customer_id'] as num?)?.toInt() ?? 0,
          'payload_json': jsonEncode(r),
        });
      }
      await reportBatch.commit(noResult: true);

      // —— طلبات ——
      final localOrders = await txn.query('orders', where: 'id < 0');
      await txn.delete('orders');
      final orders = (payload['orders'] as List? ?? []).whereType<Map>();
      final orderBatch = txn.batch();
      for (final o in orders) {
        orderBatch.insert(
          'orders',
          _orderRowFromMap(o.cast<String, dynamic>()),
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      for (final o in localOrders) {
        orderBatch.insert(
          'orders',
          o,
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await orderBatch.commit(noResult: true);

      // —— كشوف Oracle (upsert دون مسح كشوف أخرى محفوظة يدوياً) ——
      final stmts =
          (payload['oracle_statements'] as List? ?? []).whereType<Map>();
      final stmtBatch = txn.batch();
      final now = DateTime.now().toIso8601String();
      for (final s in stmts) {
        final cid = (s['customer_id'] as num?)?.toInt() ??
            (s['party_id'] as num?)?.toInt() ??
            0;
        if (cid < 1 || s['ok'] == false) continue;
        final from = (s['from'] ?? '').toString();
        final to = (s['to'] ?? '').toString();
        if (from.isEmpty || to.isEmpty) continue;
        final map = Map<String, dynamic>.from(s.cast<String, dynamic>());
        map['offline_cached'] = true;
        map['cached_at'] = (s['cached_at'] ?? now).toString();
        stmtBatch.insert(
          'oracle_statements',
          {
            'customer_id': cid,
            'from_date': from,
            'to_date': to,
            'party_name': (s['party_name'] ?? '').toString(),
            'cached_at': map['cached_at'],
            'payload_json': jsonEncode(map),
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await stmtBatch.commit(noResult: true);

      Future<void> putMeta(String k, String v) => txn.insert(
            'sync_meta',
            {'key': k, 'value': v},
            conflictAlgorithm: ConflictAlgorithm.replace,
          );

      await putMeta(
        'synced_at',
        (payload['synced_at'] ?? DateTime.now().toIso8601String()).toString(),
      );
      await putMeta(
        'default_warehouse_id',
        '${meta['default_warehouse_id'] ?? 0}',
      );
      await putMeta(
        'default_tax_percent',
        '${meta['default_tax_percent'] ?? 0}',
      );
      await putMeta(
        'auto_send_orders',
        (meta['auto_send_orders'] == true ||
                meta['auto_send_orders'] == 1 ||
                '${meta['auto_send_orders']}' == '1')
            ? '1'
            : '0',
      );
      await putMeta('decimal_places', '${meta['decimal_places'] ?? 3}');
      await putMeta(
        'visit_radius_m',
        '${meta['visit_radius_m'] ?? 200}',
      );
      await putMeta(
        'cache_from',
        (meta['cache_from'] ?? report['from'] ?? '').toString(),
      );
      await putMeta(
        'cache_to',
        (meta['cache_to'] ?? report['to'] ?? '').toString(),
      );
    });
  }

  Map<String, Object?> _orderRowFromMap(Map<String, dynamic> o,
      {String? clientUuid}) {
    final lines = o['lines'] as List? ?? o['items'] as List? ?? [];
    final totalQty = (o['total_qty'] as num?)?.toDouble() ??
        lines.fold<double>(0, (s, l) {
          if (l is! Map) return s;
          return s + ((l['qty'] as num?)?.toDouble() ?? 0);
        });
    return {
      'id': (o['id'] as num?)?.toInt() ?? 0,
      'order_no': (o['order_no'] ?? '').toString(),
      'order_date': (o['order_date'] ?? '').toString(),
      'customer_id': (o['customer_id'] as num?)?.toInt() ?? 0,
      'customer_name': (o['customer_name'] ?? '').toString(),
      'warehouse_id': (o['warehouse_id'] as num?)?.toInt() ?? 0,
      'warehouse_name': (o['warehouse_name'] ?? '').toString(),
      'status': (o['status'] ?? 'draft').toString(),
      'is_sent': (o['is_sent'] as num?)?.toInt() ?? 0,
      'total': (o['total'] as num?)?.toDouble() ?? 0,
      'line_count': (o['line_count'] as num?)?.toInt() ?? lines.length,
      'total_qty': totalQty,
      'client_uuid': clientUuid ?? o['client_uuid']?.toString(),
      'payload_json': jsonEncode(o),
    };
  }

  /// استبدال عملاء السيرفر في الكاش بقائمة المندوب الحالية (بدون مسح العملاء المحليين السالبين).
  Future<void> replaceCustomersFromLive(List<Map<String, dynamic>> rows) async {
    final db = await _db;
    await db.transaction((txn) async {
      final localCustomers = await txn.query('customers', where: 'id < 0');
      await txn.delete('customers', where: 'id > 0');
      final batch = txn.batch();
      for (final r in rows) {
        final id = (r['id'] as num?)?.toInt() ?? 0;
        if (id < 1) continue;
        batch.insert(
          'customers',
          {
            'id': id,
            'name': (r['name'] ?? r['name_ar'] ?? '').toString(),
            'code': (r['code'] ?? '').toString(),
            'phone': (r['phone'] ?? '').toString(),
            'address': (r['address'] ?? r['address_ar'] ?? '').toString(),
            'latitude': r['latitude'],
            'longitude': r['longitude'],
            'payment_period': (r['payment_period'] as num?)?.toInt() ?? 0,
            'use_wholesale_price':
                (r['use_wholesale_price'] as num?)?.toInt() ?? 0,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      for (final r in localCustomers) {
        batch.insert('customers', r, conflictAlgorithm: ConflictAlgorithm.replace);
      }
      await batch.commit(noResult: true);
    });
  }

  Future<Map<String, dynamic>?> getCustomerById(int id) async {
    if (id == 0) return null;
    final db = await _db;
    final rows = await db.query(
      'customers',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    return rows.isEmpty ? null : rows.first;
  }

  Future<List<Map<String, dynamic>>> searchCustomers(String q,
      {int limit = 500}) async {
    final db = await _db;
    final query = q.trim();
    if (query.isEmpty) {
      return db.query('customers', orderBy: 'name COLLATE NOCASE', limit: limit);
    }
    final like = '%$query%';
    return db.query(
      'customers',
      where: 'name LIKE ? OR code LIKE ? OR phone LIKE ?',
      whereArgs: [like, like, like],
      orderBy: 'name COLLATE NOCASE',
      limit: limit,
    );
  }

  Future<int> nextLocalCustomerId() async {
    final db = await _db;
    final minId = Sqflite.firstIntValue(
          await db.rawQuery('SELECT MIN(id) FROM customers WHERE id < 0'),
        ) ??
        0;
    return minId < 0 ? minId - 1 : -1;
  }

  Future<void> upsertLocalCustomer({
    required int id,
    required String name,
    String code = '',
    String phone = '',
    String address = '',
    double? latitude,
    double? longitude,
    int paymentPeriod = 0,
  }) async {
    final db = await _db;
    await db.insert(
      'customers',
      {
        'id': id,
        'name': name,
        'code': code,
        'phone': phone,
        'address': address,
        'latitude': latitude,
        'longitude': longitude,
        'payment_period': paymentPeriod,
        'use_wholesale_price': 0,
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> patchCustomerGps(
    int id, {
    double? latitude,
    double? longitude,
  }) async {
    if (id == 0) return;
    final db = await _db;
    await db.update(
      'customers',
      {
        'latitude': latitude,
        'longitude': longitude,
      },
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  Future<void> deleteLocalCustomer(int id) async {
    if (id == 0) return;
    final db = await _db;
    await db.delete('customers', where: 'id = ?', whereArgs: [id]);
  }

  /// يلغي إضافة/تعديل معلّق لنفس العميل قبل حذف محلي.
  Future<void> dropPendingCustomerMutations(int customerId) async {
    if (customerId == 0) return;
    final db = await _db;
    final rows = await db.query(
      'outbox',
      where:
          "status IN ('pending','error') AND kind IN ('customer_save','customer_update')",
    );
    for (final row in rows) {
      try {
        final body =
            jsonDecode(row['body_json'] as String) as Map<String, dynamic>;
        final cid = (body['id'] as num?)?.toInt() ??
            (body['customer_id'] as num?)?.toInt() ??
            (body['local_customer_id'] as num?)?.toInt() ??
            0;
        if (cid != customerId) continue;
        await db.delete('outbox', where: 'id = ?', whereArgs: [row['id']]);
      } catch (_) {}
    }
  }

  Future<void> rewriteLocalCustomerId(int localId, int serverId) async {
    if (localId >= 0 || serverId < 1) return;
    final db = await _db;
    await db.transaction((txn) async {
      final rows = await txn.query(
        'customers',
        where: 'id = ?',
        whereArgs: [localId],
        limit: 1,
      );
      if (rows.isNotEmpty) {
        final row = Map<String, Object?>.from(rows.first);
        row['id'] = serverId;
        await txn.delete('customers', where: 'id = ?', whereArgs: [localId]);
        await txn.insert(
          'customers',
          row,
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await txn.rawUpdate(
        'UPDATE orders SET customer_id = ? WHERE customer_id = ?',
        [serverId, localId],
      );
      final outbox = await txn.query(
        'outbox',
        where: "status IN ('pending','error')",
      );
      for (final row in outbox) {
        try {
          final body =
              jsonDecode(row['body_json'] as String) as Map<String, dynamic>;
          var changed = false;
          if ((body['customer_id'] as num?)?.toInt() == localId) {
            body['customer_id'] = serverId;
            changed = true;
          }
          if ((body['local_customer_id'] as num?)?.toInt() == localId) {
            body['local_customer_id'] = serverId;
            changed = true;
          }
          if (!changed) continue;
          await txn.update(
            'outbox',
            {
              'body_json': jsonEncode(body),
              'updated_at': DateTime.now().toIso8601String(),
            },
            where: 'id = ?',
            whereArgs: [row['id']],
          );
        } catch (_) {}
      }
      // حدّث payload الطلبات
      final orders = await txn.query(
        'orders',
        where: 'customer_id = ?',
        whereArgs: [serverId],
      );
      for (final o in orders) {
        try {
          final payload =
              jsonDecode(o['payload_json'] as String) as Map<String, dynamic>;
          payload['customer_id'] = serverId;
          await txn.update(
            'orders',
            {'payload_json': jsonEncode(payload)},
            where: 'id = ?',
            whereArgs: [o['id']],
          );
        } catch (_) {}
      }
    });
  }

  Future<List<Map<String, dynamic>>> warehouses() async {
    final db = await _db;
    return db.query('warehouses', orderBy: 'name COLLATE NOCASE');
  }

  Future<List<Map<String, dynamic>>> taxRates() async {
    final db = await _db;
    return db.query('tax_rates', orderBy: 'id');
  }

  Future<List<Map<String, dynamic>>> searchItems({
    required int warehouseId,
    String q = '',
    int limit = 200,
  }) async {
    final db = await _db;
    final query = q.trim();
    final like = '%$query%';
    final sql = query.isEmpty
        ? '''
          SELECT i.*, COALESCE(s.qty, 0) AS stock_qty
          FROM items i
          LEFT JOIN stock s ON s.item_id = i.id AND s.warehouse_id = ?
          ORDER BY i.name COLLATE NOCASE
          LIMIT ?
        '''
        : '''
          SELECT i.*, COALESCE(s.qty, 0) AS stock_qty
          FROM items i
          LEFT JOIN stock s ON s.item_id = i.id AND s.warehouse_id = ?
          WHERE i.name LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?
          ORDER BY i.name COLLATE NOCASE
          LIMIT ?
        ''';
    final args = query.isEmpty
        ? <Object>[warehouseId, limit]
        : <Object>[warehouseId, like, like, like, limit];
    return db.rawQuery(sql, args);
  }

  Future<int> enqueueOutbox({
    required String clientUuid,
    required String kind,
    required String path,
    required Map<String, dynamic> body,
    String method = 'POST_JSON',
  }) async {
    final db = await _db;
    final now = DateTime.now().toIso8601String();
    return db.insert(
      'outbox',
      {
        'client_uuid': clientUuid,
        'kind': kind,
        'path': path,
        'method': method,
        'body_json': jsonEncode(body),
        'status': 'pending',
        'attempts': 0,
        'created_at': now,
        'updated_at': now,
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<List<Map<String, dynamic>>> noOrderReasons() async {
    final db = await _db;
    try {
      return db.query('no_order_reasons', orderBy: 'id');
    } catch (_) {
      return [];
    }
  }

  Future<int> visitRadiusM() async {
    return int.tryParse(await getMeta('visit_radius_m') ?? '') ?? 200;
  }

  Future<void> saveOpenVisit(Map<String, dynamic> visit) async {
    await setMeta('open_visit_json', jsonEncode(visit));
  }

  Future<Map<String, dynamic>?> loadOpenVisit() async {
    final raw = await getMeta('open_visit_json');
    if (raw == null || raw.isEmpty) return null;
    try {
      final m = jsonDecode(raw);
      return m is Map ? m.cast<String, dynamic>() : null;
    } catch (_) {
      return null;
    }
  }

  Future<void> clearOpenVisit() async {
    await setMeta('open_visit_json', '');
  }

  Future<void> saveVisitsForDate(
    String routeDate,
    List<Map<String, dynamic>> visits, {
    String weekdayLabel = '',
  }) async {
    final db = await _db;
    if (visits.isEmpty) {
      await db.delete(
        'route_day_visits',
        where: 'route_date = ?',
        whereArgs: [routeDate],
      );
      return;
    }
    await db.insert(
      'route_day_visits',
      {
        'route_date': routeDate,
        'weekday_label': weekdayLabel,
        'visits_json': jsonEncode(visits),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> saveMonthAgenda(String monthYm, Map<String, dynamic> agenda) async {
    final db = await _db;
    final days = (agenda['days'] as List? ?? []).whereType<Map>().toList();
    await db.transaction((txn) async {
      await txn.delete(
        'route_months',
        where: 'month_ym = ?',
        whereArgs: [monthYm],
      );
      await txn.insert('route_months', {
        'month_ym': monthYm,
        'date_from': (agenda['date_from'] ?? '').toString(),
        'date_to': (agenda['date_to'] ?? '').toString(),
        'days_json': jsonEncode(days),
      });
      final keep = <String>{};
      for (final d in days) {
        final date = (d['route_date'] ?? '').toString();
        if (date.isEmpty) continue;
        keep.add(date);
        final customers =
            (d['customers'] as List? ?? d['visits'] as List? ?? []);
        await txn.insert(
          'route_day_visits',
          {
            'route_date': date,
            'weekday_label': (d['weekday_label'] ?? '').toString(),
            'visits_json': jsonEncode(customers),
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      final stale = await txn.query(
        'route_day_visits',
        where: "route_date LIKE ?",
        whereArgs: ['$monthYm-%'],
      );
      for (final row in stale) {
        final date = (row['route_date'] ?? '').toString();
        if (!keep.contains(date)) {
          await txn.delete(
            'route_day_visits',
            where: 'route_date = ?',
            whereArgs: [date],
          );
        }
      }
    });
  }

  Future<List<Map<String, dynamic>>> visitsForDate(String routeDate) async {
    final db = await _db;
    final rows = await db.query(
      'route_day_visits',
      where: 'route_date = ?',
      whereArgs: [routeDate],
      limit: 1,
    );
    if (rows.isEmpty) {
      // جرّب من أجندة الشهر
      final ym = routeDate.length >= 7 ? routeDate.substring(0, 7) : '';
      if (ym.isEmpty) return [];
      final months = await db.query(
        'route_months',
        where: 'month_ym = ?',
        whereArgs: [ym],
        limit: 1,
      );
      if (months.isEmpty) return [];
      try {
        final days = jsonDecode(months.first['days_json'] as String);
        if (days is! List) return [];
        for (final d in days) {
          if (d is! Map) continue;
          if ((d['route_date'] ?? '').toString() != routeDate) continue;
          final customers =
              (d['customers'] as List? ?? d['visits'] as List? ?? []);
          return customers
              .whereType<Map>()
              .map((e) => e.cast<String, dynamic>())
              .toList();
        }
      } catch (_) {}
      return [];
    }
    try {
      final list = jsonDecode(rows.first['visits_json'] as String);
      if (list is! List) return [];
      return list
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
    } catch (_) {
      return [];
    }
  }

  Future<String> weekdayLabelForDate(String routeDate) async {
    final db = await _db;
    final rows = await db.query(
      'route_day_visits',
      where: 'route_date = ?',
      whereArgs: [routeDate],
      limit: 1,
    );
    if (rows.isNotEmpty) {
      final w = (rows.first['weekday_label'] as String?) ?? '';
      if (w.isNotEmpty) return w;
    }
    const labels = [
      'الأحد',
      'الإثنين',
      'الثلاثاء',
      'الأربعاء',
      'الخميس',
      'الجمعة',
      'السبت',
    ];
    final d = DateTime.tryParse(routeDate);
    if (d == null) return '';
    return labels[d.weekday % 7]; // DateTime: Mon=1..Sun=7; PHP w: Sun=0
  }

  Future<Map<String, dynamic>?> monthAgenda(String monthYm) async {
    final db = await _db;
    final rows = await db.query(
      'route_months',
      where: 'month_ym = ?',
      whereArgs: [monthYm],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    try {
      final days = jsonDecode(rows.first['days_json'] as String);
      return {
        'month': monthYm,
        'date_from': rows.first['date_from'],
        'date_to': rows.first['date_to'],
        'days': days is List ? days : [],
        'visit_radius_m': await visitRadiusM(),
      };
    } catch (_) {
      return null;
    }
  }

  Future<void> patchLocalVisitCheckin({
    required String routeDate,
    required int customerId,
    required String checkinAt,
    required String method,
    required int routeLineId,
  }) async {
    final visits = await visitsForDate(routeDate);
    var found = false;
    for (var i = 0; i < visits.length; i++) {
      if ((visits[i]['customer_id'] as num?)?.toInt() == customerId) {
        visits[i] = {
          ...visits[i],
          'status': 'checked_in',
          'visit_checkin_at': checkinAt,
          'checkin_method': method,
          'route_line_id': routeLineId,
          'offline': true,
        };
        found = true;
        break;
      }
    }
    if (!found) {
      final c = await getCustomerById(customerId);
      visits.add({
        'customer_id': customerId,
        'name': c?['name'] ?? '',
        'code': c?['code'] ?? '',
        'status': 'checked_in',
        'in_plan': false,
        'visit_checkin_at': checkinAt,
        'checkin_method': method,
        'route_line_id': routeLineId,
        'has_order': false,
        'offline': true,
      });
    }
    await _saveDayVisits(routeDate, visits);
  }

  Future<void> patchLocalVisitCheckout({
    required String routeDate,
    required int customerId,
    required String checkoutAt,
    required String method,
    List<String> reasonNames = const [],
  }) async {
    final visits = await visitsForDate(routeDate);
    for (var i = 0; i < visits.length; i++) {
      if ((visits[i]['customer_id'] as num?)?.toInt() == customerId) {
        visits[i] = {
          ...visits[i],
          'status': 'checked_out',
          'visit_checkout_at': checkoutAt,
          'checkout_method': method,
          'no_order_reasons': reasonNames,
          'offline': true,
        };
        break;
      }
    }
    await _saveDayVisits(routeDate, visits);
  }

  Future<void> _saveDayVisits(
      String routeDate, List<Map<String, dynamic>> visits) async {
    final db = await _db;
    final wd = await weekdayLabelForDate(routeDate);
    await db.insert(
      'route_day_visits',
      {
        'route_date': routeDate,
        'weekday_label': wd,
        'visits_json': jsonEncode(visits),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<List<Map<String, dynamic>>> visitReportRows({
    required String from,
    required String to,
    int customerId = 0,
  }) async {
    final db = await _db;
    final rows = await db.query('visit_report_rows', orderBy: 'route_date DESC, id DESC');
    final out = <Map<String, dynamic>>[];
    for (final r in rows) {
      final date = (r['route_date'] as String?) ?? '';
      if (date.compareTo(from) < 0 || date.compareTo(to) > 0) continue;
      final cid = (r['customer_id'] as int?) ?? 0;
      if (customerId > 0 && cid != customerId) continue;
      try {
        final m = jsonDecode(r['payload_json'] as String);
        if (m is Map) out.add(m.cast<String, dynamic>());
      } catch (_) {}
    }
    return out;
  }

  Future<int> nextLocalOrderId() async {
    final db = await _db;
    final minId = Sqflite.firstIntValue(
          await db.rawQuery('SELECT MIN(id) FROM orders WHERE id < 0'),
        ) ??
        0;
    return minId < 0 ? minId - 1 : -1;
  }

  Future<void> upsertLocalOrder(
    Map<String, dynamic> order, {
    String? clientUuid,
  }) async {
    final db = await _db;
    await db.insert(
      'orders',
      _orderRowFromMap(order, clientUuid: clientUuid),
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> saveOracleStatement(Map<String, dynamic> payload) async {
    final cid = (payload['customer_id'] as num?)?.toInt() ??
        (payload['party_id'] as num?)?.toInt() ??
        0;
    final from = (payload['from'] ?? '').toString();
    final to = (payload['to'] ?? '').toString();
    if (cid < 1 || from.isEmpty || to.isEmpty) return;
    if (payload['ok'] == false) return;
    final db = await _db;
    await _ensureV3(db);
    final now = DateTime.now().toIso8601String();
    final map = Map<String, dynamic>.from(payload);
    map['offline_cached'] = true;
    map['cached_at'] = now;
    await db.insert(
      'oracle_statements',
      {
        'customer_id': cid,
        'from_date': from,
        'to_date': to,
        'party_name': (payload['party_name'] ?? '').toString(),
        'cached_at': now,
        'payload_json': jsonEncode(map),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  /// يرجع كاش مطابق للفترة، أو آخر كشف محفوظ لنفس العميل.
  Future<Map<String, dynamic>?> getOracleStatement({
    required int customerId,
    String? from,
    String? to,
  }) async {
    if (customerId < 1) return null;
    final db = await _db;
    try {
      if (from != null &&
          from.isNotEmpty &&
          to != null &&
          to.isNotEmpty) {
        final exact = await db.query(
          'oracle_statements',
          where: 'customer_id = ? AND from_date = ? AND to_date = ?',
          whereArgs: [customerId, from, to],
          limit: 1,
        );
        if (exact.isNotEmpty) {
          final m = jsonDecode(exact.first['payload_json'] as String);
          if (m is Map) return m.cast<String, dynamic>();
        }
      }
      final rows = await db.query(
        'oracle_statements',
        where: 'customer_id = ?',
        whereArgs: [customerId],
        orderBy: 'cached_at DESC',
        limit: 1,
      );
      if (rows.isEmpty) return null;
      final m = jsonDecode(rows.first['payload_json'] as String);
      if (m is Map) return m.cast<String, dynamic>();
    } catch (_) {}
    return null;
  }

  Future<int> orderIdForVisitLine(int routeLineId, {int? customerId}) async {
    if (routeLineId == 0) return 0;
    final rows = await listOrders(
      customerId: customerId != null && customerId != 0 ? customerId : null,
      limit: 100,
    );
    for (final o in rows) {
      final vid = (o['visit_route_line_id'] as num?)?.toInt() ??
          int.tryParse('${o['visit_route_line_id'] ?? ''}') ??
          0;
      if (vid == routeLineId) {
        return (o['id'] as num?)?.toInt() ??
            int.tryParse('${o['id'] ?? ''}') ??
            0;
      }
    }
    return 0;
  }

  Future<Map<String, dynamic>?> getOrderById(int id) async {
    if (id == 0) return null;
    final db = await _db;
    final rows = await db.query(
      'orders',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    try {
      final payload = jsonDecode(rows.first['payload_json'] as String);
      if (payload is Map) {
        final m = payload.cast<String, dynamic>();
        m['id'] = rows.first['id'];
        m['is_sent'] = rows.first['is_sent'];
        m['order_no'] = rows.first['order_no'];
        return m;
      }
    } catch (_) {}
    return Map<String, dynamic>.from(rows.first);
  }

  Future<List<Map<String, dynamic>>> listOrders({
    int? isSent,
    int? customerId,
    String? from,
    String? to,
    String q = '',
    int limit = 200,
    int offset = 0,
  }) async {
    final db = await _db;
    final where = <String>[];
    final args = <Object>[];
    if (isSent != null) {
      where.add('is_sent = ?');
      args.add(isSent);
    }
    if (customerId != null && customerId != 0) {
      where.add('customer_id = ?');
      args.add(customerId);
    }
    if (from != null && from.isNotEmpty) {
      where.add('order_date >= ?');
      args.add(from);
    }
    if (to != null && to.isNotEmpty) {
      where.add('order_date <= ?');
      args.add(to);
    }
    final query = q.trim();
    if (query.isNotEmpty) {
      where.add('(order_no LIKE ? OR customer_name LIKE ?)');
      args.add('%$query%');
      args.add('%$query%');
    }
    final sql = '''
      SELECT * FROM orders
      ${where.isEmpty ? '' : 'WHERE ${where.join(' AND ')}'}
      ORDER BY order_date DESC, id DESC
      LIMIT ? OFFSET ?
    ''';
    args.add(limit);
    args.add(offset);
    final rows = await db.rawQuery(sql, args);
    return rows.map((r) {
      try {
        final payload = jsonDecode(r['payload_json'] as String);
        if (payload is Map) {
          final m = payload.cast<String, dynamic>();
          m['id'] = r['id'];
          m['is_sent'] = r['is_sent'];
          m['order_no'] = r['order_no'];
          m['order_date'] = r['order_date'];
          m['customer_name'] = r['customer_name'];
          m['total'] = r['total'];
          m['line_count'] = r['line_count'];
          m['total_qty'] = r['total_qty'];
          m['client_uuid'] = r['client_uuid'];
          return m;
        }
      } catch (_) {}
      return Map<String, dynamic>.from(r);
    }).toList();
  }

  Future<int> countOrders({
    int? isSent,
    int? customerId,
    String? from,
    String? to,
    String q = '',
  }) async {
    final db = await _db;
    final where = <String>[];
    final args = <Object>[];
    if (isSent != null) {
      where.add('is_sent = ?');
      args.add(isSent);
    }
    if (customerId != null && customerId != 0) {
      where.add('customer_id = ?');
      args.add(customerId);
    }
    if (from != null && from.isNotEmpty) {
      where.add('order_date >= ?');
      args.add(from);
    }
    if (to != null && to.isNotEmpty) {
      where.add('order_date <= ?');
      args.add(to);
    }
    final query = q.trim();
    if (query.isNotEmpty) {
      where.add('(order_no LIKE ? OR customer_name LIKE ?)');
      args.add('%$query%');
      args.add('%$query%');
    }
    final sql =
        'SELECT COUNT(*) FROM orders ${where.isEmpty ? '' : 'WHERE ${where.join(' AND ')}'}';
    return Sqflite.firstIntValue(await db.rawQuery(sql, args)) ?? 0;
  }

  Future<void> markOrderSent(List<int> ids) async {
    if (ids.isEmpty) return;
    final db = await _db;
    final placeholders = List.filled(ids.length, '?').join(',');
    await db.rawUpdate(
      'UPDATE orders SET is_sent = 1 WHERE id IN ($placeholders)',
      ids,
    );
    for (final id in ids) {
      final o = await getOrderById(id);
      if (o == null) continue;
      o['is_sent'] = 1;
      await upsertLocalOrder(o);
    }
  }

  Future<void> deleteLocalOrder(int id) async {
    final db = await _db;
    await db.delete('orders', where: 'id = ?', whereArgs: [id]);
    if (id < 0) {
      // ألغِ عمليات الطابور المرتبطة بهذا الطلب المحلي
      final rows = await db.query(
        'outbox',
        where: "status IN ('pending','error')",
      );
      for (final row in rows) {
        try {
          final body =
              jsonDecode(row['body_json'] as String) as Map<String, dynamic>;
          final kind = (row['kind'] as String?) ?? '';
          final localOid = (body['local_order_id'] as num?)?.toInt() ??
              (body['id'] as num?)?.toInt() ??
              0;
          if (kind == 'customer_order_save' && localOid == id) {
            await db.delete('outbox', where: 'id = ?', whereArgs: [row['id']]);
            continue;
          }
          if (kind == 'customer_order_send') {
            final ids = (body['ids'] as List? ?? [])
                .map((e) => (e as num?)?.toInt() ?? 0)
                .where((e) => e != id)
                .toList();
            if (ids.isEmpty) {
              await db.delete('outbox', where: 'id = ?', whereArgs: [row['id']]);
            } else if (ids.length != (body['ids'] as List).length) {
              body['ids'] = ids;
              await db.update(
                'outbox',
                {'body_json': jsonEncode(body)},
                where: 'id = ?',
                whereArgs: [row['id']],
              );
            }
          }
          if (kind == 'customer_order_delete' &&
              (body['id'] as num?)?.toInt() == id) {
            await db.delete('outbox', where: 'id = ?', whereArgs: [row['id']]);
          }
        } catch (_) {}
      }
    }
  }

  Future<void> rewriteLocalOrderId({
    required int localId,
    required int serverId,
    String? orderNo,
  }) async {
    if (localId >= 0 || serverId < 1) return;
    final db = await _db;
    await db.transaction((txn) async {
      final rows = await txn.query(
        'orders',
        where: 'id = ?',
        whereArgs: [localId],
        limit: 1,
      );
      if (rows.isEmpty) return;
      final row = Map<String, Object?>.from(rows.first);
      row['id'] = serverId;
      if (orderNo != null && orderNo.isNotEmpty) {
        row['order_no'] = orderNo;
      }
      try {
        final payload =
            jsonDecode(row['payload_json'] as String) as Map<String, dynamic>;
        payload['id'] = serverId;
        if (orderNo != null && orderNo.isNotEmpty) {
          payload['order_no'] = orderNo;
        }
        row['payload_json'] = jsonEncode(payload);
      } catch (_) {}
      await txn.delete('orders', where: 'id = ?', whereArgs: [localId]);
      await txn.insert(
        'orders',
        row,
        conflictAlgorithm: ConflictAlgorithm.replace,
      );

      final outbox = await txn.query(
        'outbox',
        where: "status IN ('pending','error')",
      );
      for (final ob in outbox) {
        try {
          final body =
              jsonDecode(ob['body_json'] as String) as Map<String, dynamic>;
          var changed = false;
          if ((body['id'] as num?)?.toInt() == localId) {
            body['id'] = serverId;
            changed = true;
          }
          final ids = body['ids'];
          if (ids is List) {
            final next = ids.map((e) {
              final n = (e as num?)?.toInt() ?? 0;
              return n == localId ? serverId : n;
            }).toList();
            if (next.toString() != ids.toString()) {
              body['ids'] = next;
              changed = true;
            }
          }
          if (!changed) continue;
          await txn.update(
            'outbox',
            {
              'body_json': jsonEncode(body),
              'updated_at': DateTime.now().toIso8601String(),
            },
            where: 'id = ?',
            whereArgs: [ob['id']],
          );
        } catch (_) {}
      }
    });
  }

  /// بعد نجاح check-in على السيرفر: اربط طلبات offline بالـ route_line_id الحقيقي.
  Future<int> rewritePendingOrdersVisitLine({
    required int customerId,
    required int routeLineId,
  }) async {
    if (customerId < 1 || routeLineId < 1) return 0;
    final db = await _db;
    final rows = await db.query(
      'outbox',
      where: "status IN ('pending','error') AND kind = ?",
      whereArgs: ['customer_order_save'],
    );
    var n = 0;
    for (final row in rows) {
      try {
        final body =
            jsonDecode(row['body_json'] as String) as Map<String, dynamic>;
        final cid = (body['customer_id'] as num?)?.toInt() ?? 0;
        if (cid != customerId) continue;
        body['visit_route_line_id'] = routeLineId;
        body.remove('offline_visit');
        await db.update(
          'outbox',
          {
            'body_json': jsonEncode(body),
            'updated_at': DateTime.now().toIso8601String(),
          },
          where: 'id = ?',
          whereArgs: [row['id']],
        );
        n++;
      } catch (_) {}
    }
    return n;
  }

  Future<List<Map<String, dynamic>>> pendingOutbox({int limit = 50}) async {
    final db = await _db;
    return db.rawQuery(
      '''
      SELECT * FROM outbox
      WHERE status IN ('pending','error')
      ORDER BY
        CASE kind
          WHEN 'visit_checkin' THEN 1
          WHEN 'customer_save' THEN 2
          WHEN 'customer_update' THEN 3
          WHEN 'customer_delete' THEN 4
          WHEN 'customer_order_save' THEN 5
          WHEN 'customer_order_send' THEN 6
          WHEN 'visit_checkout' THEN 7
          WHEN 'customer_order_delete' THEN 8
          ELSE 9
        END,
        id ASC
      LIMIT ?
      ''',
      [limit],
    );
  }

  Future<void> markOutboxDone(int id) async {
    final db = await _db;
    await db.update(
      'outbox',
      {
        'status': 'done',
        'last_error': null,
        'updated_at': DateTime.now().toIso8601String(),
      },
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  Future<void> markOutboxError(int id, String error) async {
    final db = await _db;
    await db.rawUpdate(
      '''
      UPDATE outbox
      SET status = 'error',
          attempts = attempts + 1,
          last_error = ?,
          updated_at = ?
      WHERE id = ?
      ''',
      [error, DateTime.now().toIso8601String(), id],
    );
  }

  /// هل الإرسال التلقائي للطلبات مفعّل من إعدادات الشركة؟
  Future<bool> autoSendOrdersEnabled() async {
    final v = await getMeta('auto_send_orders');
    return v == '1' || v == 'true';
  }

  /// تنظيف الطابور: حذف المنتهية، وإغلاق إرسال طلبات أصبحت مرسلة محلياً.
  Future<int> cleanupOutbox() async {
    final db = await _db;
    var n = 0;
    n += await db.delete('outbox', where: "status = 'done'");

    final errors = await db.query(
      'outbox',
      where: "status = 'error' AND kind = 'customer_order_send'",
    );
    for (final row in errors) {
      final id = row['id'] as int? ?? 0;
      if (id < 1) continue;
      try {
        final body =
            jsonDecode(row['body_json'] as String) as Map<String, dynamic>;
        final ids = (body['ids'] as List? ?? [])
            .map((e) => (e as num?)?.toInt() ?? 0)
            .where((e) => e != 0)
            .toList();
        if (ids.isEmpty) {
          await markOutboxDone(id);
          n++;
          continue;
        }
        var allSent = true;
        for (final oid in ids) {
          final rows = await db.query(
            'orders',
            columns: ['is_sent'],
            where: 'id = ?',
            whereArgs: [oid],
            limit: 1,
          );
          if (rows.isEmpty) continue;
          if ((rows.first['is_sent'] as int? ?? 0) != 1) {
            allSent = false;
            break;
          }
        }
        if (allSent) {
          await markOutboxDone(id);
          n++;
        }
      } catch (_) {}
    }

    // أخطاء تجاوزت محاولات كثيرة — أرشفة حتى لا تظهر كمعلّقة إلى الأبد.
    n += await db.rawUpdate(
      "UPDATE outbox SET status = 'failed' WHERE status = 'error' AND attempts >= 8",
    );
    n += await db.delete(
      'outbox',
      where: "status = 'failed' AND updated_at < ?",
      whereArgs: [
        DateTime.now().subtract(const Duration(days: 7)).toIso8601String(),
      ],
    );
    return n;
  }

  /// إعادة محاولة الأخطاء (error → pending) قبل الترحيل.
  Future<int> requeueOutboxErrors({int maxAttempts = 7}) async {
    final db = await _db;
    return db.rawUpdate(
      '''
      UPDATE outbox
      SET status = 'pending', updated_at = ?
      WHERE status = 'error' AND attempts < ?
      ''',
      [DateTime.now().toIso8601String(), maxAttempts],
    );
  }
}
