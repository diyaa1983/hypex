-- مجموعات الصلاحيات الظاهرة في شاشة «تتبع مواقع المندوبين».
CREATE TABLE IF NOT EXISTS sys_gps_track_group (
  group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (group_id),
  CONSTRAINT fk_sys_gps_track_group
    FOREIGN KEY (group_id) REFERENCES sys_group (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تحديث تسمية الشاشة
UPDATE sys_screen
   SET name_ar = 'تتبع مواقع المندوبين'
 WHERE code IN ('user_gps_tracker', 'm_user_gps_tracker');
