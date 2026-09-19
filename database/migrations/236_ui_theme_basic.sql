-- توحيد اسم الواجهة: modern → basic
UPDATE sys_company_settings SET ui_theme = 'basic' WHERE ui_theme = 'modern';
UPDATE sys_user SET ui_theme = 'basic' WHERE ui_theme = 'modern';
