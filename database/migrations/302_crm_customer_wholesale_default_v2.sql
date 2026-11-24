-- إصلاح v2: ترحيل 268 كان DEFAULT 0 (كبار العملاء)
CREATE TABLE IF NOT EXISTS sys_schema_flag (
  flag_key VARCHAR(64) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

UPDATE crm_customer
SET use_wholesale_price = 1
WHERE use_wholesale_price <> 1 OR use_wholesale_price IS NULL;

ALTER TABLE crm_customer
  MODIFY use_wholesale_price TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '1=الجملة والتجزئة، 0=سعر كبار العملاء';

INSERT INTO sys_schema_flag (flag_key)
SELECT 'cust_retail_wholesale_default_v2' FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM sys_schema_flag WHERE flag_key = 'cust_retail_wholesale_default_v2'
);
