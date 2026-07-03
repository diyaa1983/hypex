-- صلاحيات المستودعات حسب المجموعة: عرض / صرف

CREATE TABLE IF NOT EXISTS sys_group_warehouse (
    group_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    can_view TINYINT(1) NOT NULL DEFAULT 0,
    can_issue TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (group_id, warehouse_id),
    KEY idx_sgw_wh (warehouse_id),
    CONSTRAINT fk_sgw_group FOREIGN KEY (group_id) REFERENCES sys_group (id) ON DELETE CASCADE,
    CONSTRAINT fk_sgw_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
