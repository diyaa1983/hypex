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
  });

  final bool hasData;
  final String? syncedAt;
  final int customers;
  final int items;
  final int warehouses;
  final int stockRows;
  final int pendingOutbox;
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
    return OfflineSyncInfo(
      hasData: syncedAt != null && syncedAt.isNotEmpty,
      syncedAt: syncedAt,
      customers: customers,
      items: items,
      warehouses: warehouses,
      stockRows: stockRows,
      pendingOutbox: pending,
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
    });
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

  Future<List<Map<String, dynamic>>> pendingOutbox({int limit = 50}) async {
    final db = await _db;
    return db.query(
      'outbox',
      where: "status IN ('pending','error')",
      orderBy: 'id ASC',
      limit: limit,
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
