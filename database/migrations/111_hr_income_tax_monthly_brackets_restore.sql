-- استعادة الشرائح الشهرية (تراجع عن 110)
-- ⚠️ يُنفَّذ يدوياً مرة واحدة فقط — لا يُضاف إلى index.php (يحذف كل الشرائح ويعيد إنشاءها).

DELETE FROM hr_income_tax_bracket WHERE marital_status IN ('single', 'married');

INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order) VALUES
('single', 751.000, 1000.000, 5.000, 10),
('single', 1001.000, 1500.000, 10.000, 20),
('single', 1501.000, 3000.000, 15.000, 30),
('single', 3001.000, NULL, 20.000, 40),
('married', 1501.000, 2000.000, 5.000, 10),
('married', 2001.000, 3000.000, 10.000, 20),
('married', 3001.000, 5000.000, 15.000, 30),
('married', 5001.000, NULL, 20.000, 40);

