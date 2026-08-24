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
    this.noOrderReasons = 0,
    this.visitRadiusM = 200,
  });

  final bool hasData;
  final String? syncedAt;
  final int customers;
  final int items;
  final int warehouses;
  final int stockRows;
  final int pendingOutbox;
  final int noOrderReasons;
  final int visitRadiusM;
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
            "SELECT COUNT(*) FROM outbox WHERE status = 'pending' OR status = 'error'",
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
    final radius = await visitRadiusM();
    return OfflineSyncInfo(
      hasData: syncedAt != null && syncedAt.isNotEmpty,
      syncedAt: syncedAt,
      customers: customers,
      items: items,
      warehouses: warehouses,
      stockRows: stockRows,
      pendingOutbox: pending,
      noOrderReasons: reasons,
      visitRadiusM: radius,
    );
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
      await txn.delete('customers');
      await txn.delete('warehouses');
      await txn.delete('tax_rates');
      await txn.delete('items');
      await txn.delete('stock');

      final custBatch = txn.batch();
      for (final r in customers) {
        custBatch.insert('customers', {
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
        });
      }
      await custBatch.commit(noResult: true);

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
        final reasons = (payload['no_order_reasons'] as List? ?? []).whereType<Map>();
        if (reasons.isEmpty) {
          // احتياط محلي إن لم يُرجع السيرفر أسباباً
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
      } catch (e) {
        // ignore table issues
      }

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
      await putMeta('decimal_places', '${meta['decimal_places'] ?? 3}');
      await putMeta(
        'visit_radius_m',
        '${meta['visit_radius_m'] ?? 200}',
      );
    });
  }

  Future<Map<String, dynamic>?> getCustomerById(int id) async {
    if (id < 1) return null;
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

  /// بعد نجاح check-in على السيرفر: اربط طلبات Offline بالـ route_line_id الحقيقي.
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
    // ترتيب: دخول زيارة → طلبات → خروج
    return db.rawQuery(
      '''
      SELECT * FROM outbox
      WHERE status IN ('pending','error')
      ORDER BY
        CASE kind
          WHEN 'visit_checkin' THEN 1
          WHEN 'customer_order_save' THEN 2
          WHEN 'visit_checkout' THEN 3
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
}
