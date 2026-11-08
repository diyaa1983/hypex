'use strict';
const db = require('./src/db');
(async () => {
  const fks = await db.query(
    `SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND REFERENCED_TABLE_NAME IN ('sal_rep_tour', 'sal_rep_tour_line')`
  );
  console.log(JSON.stringify(fks, null, 2));
  const tables = await db.query(
    `SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%tour%'`
  );
  for (const t of tables) {
    const n = await db.query(`SELECT COUNT(*) AS c FROM \`${t.TABLE_NAME}\``);
    console.log(t.TABLE_NAME, n[0].c);
  }
  process.exit(0);
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
