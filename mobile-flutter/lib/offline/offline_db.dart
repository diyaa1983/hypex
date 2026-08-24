import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

/// قاعدة SQLite المحلية للعمل Offline.
class OfflineDb {
  OfflineDb._();
  static Database? _db;

  static Future<Database> instance() async {
    if (_db != null) return _db!;
    final dir = await getDatabasesPath();
    final path = p.join(dir, 'hypex_offline.db');
    _db = await openDatabase(
      path,
      version: 2,
      onCreate: (db, version) async {
        await _createV1(db);
        await _createV2(db);
      },
      onUpgrade: (db, oldVersion, newVersion) async {
        if (oldVersion < 2) {
          await _createV2(db);
        }
      },
    );
    return _db!;
  }

  static Future<void> _createV1(Database db) async {
        await db.execute('''
          CREATE TABLE sync_meta (
            key TEXT PRIMARY KEY NOT NULL,
            value TEXT NOT NULL
          )
        ''');
        await db.execute('''
          CREATE TABLE customers (
            id INTEGER PRIMARY KEY NOT NULL,
            name TEXT NOT NULL,
            code TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL DEFAULT '',
            address TEXT NOT NULL DEFAULT '',
            latitude REAL,
            longitude REAL,
            payment_period INTEGER NOT NULL DEFAULT 0,
            use_wholesale_price INTEGER NOT NULL DEFAULT 0
          )
        ''');
        await db.execute(
          'CREATE INDEX idx_customers_name ON customers(name)',
        );
        await db.execute('''
          CREATE TABLE warehouses (
            id INTEGER PRIMARY KEY NOT NULL,
            name TEXT NOT NULL
          )
        ''');
        await db.execute('''
          CREATE TABLE tax_rates (
            id INTEGER PRIMARY KEY NOT NULL,
            name TEXT NOT NULL,
            rate_percent REAL NOT NULL
          )
        ''');
        await db.execute('''
          CREATE TABLE items (
            id INTEGER PRIMARY KEY NOT NULL,
            name TEXT NOT NULL,
            sku TEXT NOT NULL DEFAULT '',
            barcode TEXT NOT NULL DEFAULT '',
            sale_price REAL NOT NULL DEFAULT 0,
            wholesale_price REAL NOT NULL DEFAULT 0,
            units_json TEXT NOT NULL DEFAULT '[]'
          )
        ''');
        await db.execute('CREATE INDEX idx_items_name ON items(name)');
        await db.execute('CREATE INDEX idx_items_sku ON items(sku)');
        await db.execute('CREATE INDEX idx_items_barcode ON items(barcode)');
        await db.execute('''
          CREATE TABLE stock (
            warehouse_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            qty REAL NOT NULL DEFAULT 0,
            PRIMARY KEY (warehouse_id, item_id)
          )
        ''');
        await db.execute('''
          CREATE TABLE outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_uuid TEXT NOT NULL UNIQUE,
            kind TEXT NOT NULL,
            path TEXT NOT NULL,
            method TEXT NOT NULL DEFAULT 'POST_JSON',
            body_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
          )
        ''');
        await db.execute(
          'CREATE INDEX idx_outbox_status ON outbox(status, id)',
        );
  }

  static Future<void> _createV2(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS no_order_reasons (
        id INTEGER PRIMARY KEY NOT NULL,
        name_ar TEXT NOT NULL
      )
    ''');
  }
}
