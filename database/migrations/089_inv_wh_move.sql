-- حركات المستودع (رأس + بنود)

CREATE TABLE IF NOT EXISTS inv_wh_move (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  move_no VARCHAR(20) NOT NULL,
  move_date DATE NOT NULL,
  movement_type_code VARCHAR(40) NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  warehouse_to_id INT UNSIGNED NULL,
  status ENUM('draft','posted') NOT NULL DEFAULT 'draft',
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at DATETIME NULL,
  UNIQUE KEY uq_inv_wh_move_no (move_no),
  KEY idx_inv_wh_move_date (move_date),
  KEY idx_inv_wh_move_type (movement_type_code),
  KEY idx_inv_wh_move_status (status),
  CONSTRAINT fk_iwm_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id),
  CONSTRAINT fk_iwm_wh_to FOREIGN KEY (warehouse_to_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
  CONSTRAINT fk_iwm_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_wh_move_line (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  move_id BIGINT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(18,6) NOT NULL,
  CONSTRAINT fk_iwml_move FOREIGN KEY (move_id) REFERENCES inv_wh_move(id) ON DELETE CASCADE,
  CONSTRAINT fk_iwml_item FOREIGN KEY (item_id) REFERENCES inv_item(id),
  UNIQUE KEY uq_iwml_move_line (move_id, line_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'warehouse_moves', 'حركات المستودع', 'screen', 115
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'warehouse_moves');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'warehouses'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'warehouse_moves';
