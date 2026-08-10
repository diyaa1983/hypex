'use strict';
const db = require('./src/db');

(async () => {
  try {
    const tot = await db.query('SELECT COUNT(*) AS c FROM crm_customer');
    const withR = await db.query(
      'SELECT COUNT(*) AS c FROM crm_customer WHERE region_id IS NOT NULL AND region_id > 0'
    );
    console.log('total', tot[0], 'with_region', withR[0]);

    const rows = await db.query(
      `SELECT id, code, name_ar, region_id, region_address_id, sales_rep_id, oracle_key
       FROM crm_customer
       WHERE code = ? OR code LIKE ? OR name_ar LIKE ? OR IFNULL(oracle_key,'') LIKE ?
       LIMIT 20`,
      ['11200941', '%11200941%', '%المصري/الزرقاء%', '%11200941%']
    );
    console.log('match', JSON.stringify(rows, null, 2));

    if (rows[0]) {
      const id = rows[0].id;
      const reps = await db.query(
        `SELECT * FROM crm_customer_sales_rep WHERE customer_id = ?`,
        [id]
      );
      console.log('rep_links', reps);
      if (rows[0].region_id) {
        const rg = await db.query(`SELECT * FROM crm_region WHERE id = ?`, [rows[0].region_id]);
        console.log('region', rg);
      }
    }

    // sample of linked customers
    const sample = await db.query(
      `SELECT code, name_ar, region_id, region_address_id, sales_rep_id
       FROM crm_customer WHERE region_id IS NOT NULL LIMIT 5`
    );
    console.log('sample_linked', sample);

    // codes that look like 11200
    const codes = await db.query(
      `SELECT code, CHAR_LENGTH(code) AS len, region_id FROM crm_customer
       WHERE code LIKE '1120%' ORDER BY id DESC LIMIT 10`
    );
    console.log('codes_1120', codes);
  } catch (e) {
    console.error(e);
  }
  process.exit(0);
})();
