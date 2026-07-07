<?php
declare(strict_types=1);

/**
 * هيكل القائمة الجانبية وصلاحيات المجموعات.
 * كل مجال (domain) في الشريط → المجلدات (subgroups) في المحتوى الرئيسي → الشاشات داخل المجلد.
 * المفتاح `r` يطابق sys_screen.code و config/routes.php.
 */
return [
    'domains' => [
        [
            'id' => 'main',
            'title' => 'رئيسي',
            'subgroups' => [
                [
                    'id' => 'general',
                    'title' => 'عام',
                    'items' => [
                        ['r' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '⌂'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'sales',
            'title' => 'المبيعات',
            'subgroups' => [
                [
                    'id' => 'operations',
                    'title' => 'المبيعات',
                    'items' => [
                        ['r' => 'sales_invoices', 'label' => 'فاتورة مبيعات', 'icon' => '🧾'],
                        ['r' => 'sales_documents_list', 'label' => 'قائمة فواتير المبيعات', 'icon' => '📑'],
                        ['r' => 'sales_invoices_list', 'label' => 'ترحيل فواتير المبيعات', 'icon' => '📋'],
                    ],
                ],
                [
                    'id' => 'sales_delivery',
                    'title' => 'سند التسليم',
                    'subgroups' => [
                        [
                            'id' => 'operations',
                            'title' => 'سند التسليم',
                            'items' => [
                                ['r' => 'sales_delivery', 'label' => 'سند تسليم بضاعة', 'icon' => '📦'],
                            ],
                        ],
                        [
                            'id' => 'reports',
                            'title' => 'التقارير',
                            'items' => [
                                ['r' => 'report_sales_delivery', 'label' => 'تقرير سندات البضاعة', 'icon' => '📦'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'sales_returns',
                    'title' => 'مرتجعات المبيعات',
                    'subgroups' => [
                        [
                            'id' => 'operations',
                            'title' => 'مرتجعات المبيعات',
                            'items' => [
                                ['r' => 'sales_returns', 'label' => 'مرتجع مبيعات', 'icon' => '↩'],
                                ['r' => 'sales_returns_documents_list', 'label' => 'قائمة المرتجعات', 'icon' => '↩'],
                                ['r' => 'sales_returns_list', 'label' => 'ترحيل مرتجعات المبيعات', 'icon' => '📋'],
                            ],
                        ],
                        [
                            'id' => 'reports',
                            'title' => 'التقارير',
                            'items' => [
                                ['r' => 'report_sales_returns', 'label' => 'تقرير المرتجعات', 'icon' => '↩️'],
                                ['r' => 'report_sales_returns_totals', 'label' => 'إجمالي المرتجعات', 'icon' => '∑'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'reports',
                    'title' => 'التقارير',
                    'items' => [
                        ['r' => 'report_sales', 'label' => 'تقرير المبيعات الشهري حسب العميل', 'icon' => '📈'],
                        ['r' => 'report_sales_between_dates', 'label' => 'تقرير المبيعات بين تاريخين', 'icon' => '📆'],
                        ['r' => 'report_sales_by_item', 'label' => 'تقرير المبيعات حسب المادة', 'icon' => '📦'],
                        ['r' => 'report_sales_qty_extra', 'label' => 'تقرير الكميات الإضافية على الفواتير', 'icon' => '➕'],
                        ['r' => 'report_sales_invoice_discount', 'label' => 'الخصم على الفواتير', 'icon' => '🏷️'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'customers',
            'title' => 'العملاء',
            'subgroups' => [
                [
                    'id' => 'operations',
                    'title' => 'العملاء',
                    'items' => [
                        ['r' => 'customers', 'label' => 'العملاء', 'icon' => '👤'],
                    ],
                ],
                [
                    'id' => 'reports',
                    'title' => 'التقارير',
                    'items' => [
                        ['r' => 'report_customers', 'label' => 'تقرير العملاء', 'icon' => '👥'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'sales_reps',
            'title' => 'المندوبين',
            'subgroups' => [
                [
                    'id' => 'operations',
                    'title' => 'المندوبين',
                    'items' => [
                        ['r' => 'sales_reps', 'label' => 'المندوبين', 'icon' => '🧑‍💼'],
                    ],
                ],
                [
                    'id' => 'reports',
                    'title' => 'التقارير',
                    'items' => [
                        ['r' => 'report_sales_by_rep', 'label' => 'تقرير المبيعات حسب المندوب', 'icon' => '📊'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'purchases',
            'title' => 'المشتريات',
            'subgroups' => [
                [
                    'id' => 'purchase_orders',
                    'title' => 'طلبات الشراء',
                    'items' => [
                        ['r' => 'purchase_orders', 'label' => 'طلب شراء', 'icon' => '📝'],
                        ['r' => 'purchase_orders_documents_list', 'label' => 'قائمة طلبات الشراء', 'icon' => '📋'],
                        ['r' => 'purchase_orders_list', 'label' => 'اعتماد طلبات الشراء', 'icon' => '✅'],
                    ],
                ],
                [
                    'id' => 'purchase_orders_reports',
                    'title' => 'تقارير طلبات الشراء',
                    'items' => [
                        ['r' => 'report_purchase_orders', 'label' => 'تقرير طلبات الشراء', 'icon' => '📊'],
                        ['r' => 'report_purchase_orders_by_item', 'label' => 'تقرير طلبات الشراء حسب المادة', 'icon' => '📦'],
                        ['r' => 'report_purchase_orders_open', 'label' => 'تقرير الطلبات المفتوحة', 'icon' => '📂'],
                    ],
                ],
                [
                    'id' => 'operations',
                    'title' => 'المشتريات',
                    'items' => [
                        ['r' => 'purchase_invoices', 'label' => 'فاتورة شراء', 'icon' => '📥'],
                        ['r' => 'purchase_documents_list', 'label' => 'قائمة فواتير الشراء', 'icon' => '📑'],
                        ['r' => 'purchase_returns_documents_list', 'label' => 'قائمة مردودات المشتريات', 'icon' => '↩'],
                        ['r' => 'purchase_invoices_list', 'label' => 'ترحيل فواتير الشراء', 'icon' => '📋'],
                        ['r' => 'purchase_returns', 'label' => 'مردود مشتريات', 'icon' => '↩'],
                        ['r' => 'purchase_returns_list', 'label' => 'ترحيل مردودات المشتريات', 'icon' => '📋'],
                        ['r' => 'suppliers', 'label' => 'الموردون', 'icon' => '🏭'],
                    ],
                ],
                [
                    'id' => 'reports',
                    'title' => 'تقارير المشتريات',
                    'items' => [
                        ['r' => 'report_purchases', 'label' => 'تقرير المشتريات بين تاريخين', 'icon' => '📈'],
                        ['r' => 'report_purchases_by_item', 'label' => 'تقرير المشتريات حسب المادة', 'icon' => '📦'],
                        ['r' => 'report_purchase_returns', 'label' => 'تقرير مرتجعات المشتريات', 'icon' => '↩️'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'inventory',
            'title' => 'المستودعات',
            'subgroups' => [
                [
                    'id' => 'operations',
                    'title' => 'المستودعات',
                    'items' => [
                        ['r' => 'warehouses', 'label' => 'المستودعات', 'icon' => '📦'],
                        ['r' => 'inv_movement_types_settings', 'label' => 'إعداد أنواع الحركات', 'icon' => '⚙'],
                        ['r' => 'items', 'label' => 'المواد والأصناف', 'icon' => '🏷'],
                        ['r' => 'item_categories', 'label' => 'فئات المواد', 'icon' => '📦'],
                        ['r' => 'item_units', 'label' => 'وحدات القياس', 'icon' => '📐'],
                        ['r' => 'warehouse_moves', 'label' => 'حركات المستودع', 'icon' => '🔄'],
                    ],
                ],
                [
                    'id' => 'warehouse_reports',
                    'title' => 'تقارير المستودعات',
                    'items' => [
                        ['r' => 'item_stock_movements', 'label' => 'كشف حركات مادة', 'icon' => '📋'],
                        ['r' => 'report_warehouse_items', 'label' => 'تقرير المواد', 'icon' => '📋'],
                        ['r' => 'report_warehouse_zero_qty', 'label' => 'المواد التي رصيدها صفر', 'icon' => '0️⃣'],
                        ['r' => 'report_warehouse_negative_qty', 'label' => 'تقرير المواد السالبة', 'icon' => '➖'],
                        ['r' => 'report_warehouse_financial', 'label' => 'أرصدة المستودع المالية', 'icon' => '💰'],
                        ['r' => 'report_warehouse_moves', 'label' => 'تقرير حركات المستودعات', 'icon' => '🔄'],
                    ],
                ],
                [
                    'id' => 'price_adjust',
                    'title' => 'تعديل الأسعار',
                    'items' => [
                        ['r' => 'item_sale_price_adjust', 'label' => 'تعديل أسعار المواد', 'icon' => '💰'],
                    ],
                ],
                [
                    'id' => 'stocktaking',
                    'title' => 'الجرد',
                    'items' => [
                        ['r' => 'inventory_stocktake', 'label' => 'سند جرد المواد', 'icon' => '🧮'],
                        ['r' => 'report_stocktake_list', 'label' => 'قوائم الجرد', 'icon' => '📑'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'accounting',
            'title' => 'المحاسبة',
            'subgroups' => [
                [
                    'id' => 'finance',
                    'title' => 'العمليات المالية',
                    'items' => [
                        ['r' => 'account_mapping', 'label' => 'ربط الحسابات', 'icon' => '🔗'],
                        ['r' => 'cash_receipt', 'label' => 'سند قبض', 'icon' => '⬆'],
                        ['r' => 'cash_receipts_list', 'label' => 'ترحيل سندات القبض', 'icon' => '📋'],
                        ['r' => 'cash_payment', 'label' => 'سند صرف', 'icon' => '⬇'],
                        ['r' => 'cash_payments_list', 'label' => 'ترحيل سندات الصرف', 'icon' => '📋'],
                        ['r' => 'fin_employee_advances', 'label' => 'السلف', 'icon' => '💰'],
                        ['r' => 'journal_voucher', 'label' => 'سند قيد', 'icon' => '⚖'],
                        ['r' => 'debit_notes', 'label' => 'إشعارات مدينة', 'icon' => 'Ⓓ'],
                        ['r' => 'credit_notes', 'label' => 'إشعارات دائنة', 'icon' => 'Ⓒ'],
                    ],
                ],
                [
                    'id' => 'operations',
                    'title' => 'المحاسبة',
                    'items' => [
                        ['r' => 'chart_of_accounts', 'label' => 'شجرة الحسابات', 'icon' => '🌳'],
                        ['r' => 'journal_entries', 'label' => 'القيود المحاسبية', 'icon' => '⚖'],
                        ['r' => 'fin_checks', 'label' => 'الشيكات الواردة', 'icon' => '📝'],
                        ['r' => 'fin_outgoing_checks', 'label' => 'سجل الشيكات الصادرة', 'icon' => '📤'],
                        ['r' => 'report_general_ledger', 'label' => 'دفتر الأستاذ العام', 'icon' => '📖'],
                        ['r' => 'acc_period_close', 'label' => 'إغلاق الأشهر المحاسبية', 'icon' => '🔒'],
                    ],
                ],
                [
                    'id' => 'reports',
                    'title' => 'التقارير',
                    'items' => [
                        ['r' => 'report_vouchers', 'label' => 'تقرير سندات القبض / الصرف', 'icon' => '📒'],
                        ['r' => 'report_cancelled_vouchers', 'label' => 'قائمة السندات الملغاة', 'icon' => '🚫'],
                        ['r' => 'report_chart_of_accounts', 'label' => 'طباعة شجرة الحسابات', 'icon' => '🌳'],
                        ['r' => 'report_receivables', 'label' => 'كشف ذمم العملاء', 'icon' => '📒'],
                        ['r' => 'report_receivables_aging', 'label' => 'أعمار الذمم', 'icon' => '📊'],
                        ['r' => 'report_incoming_checks', 'label' => 'تقرير الشيكات الواردة', 'icon' => '📒'],
                        ['r' => 'report_outgoing_checks', 'label' => 'تقرير الشيكات الصادرة', 'icon' => '📒'],
                        ['r' => 'report_supplier_payables', 'label' => 'كشف ذمم الموردين', 'icon' => '📒'],
                        ['r' => 'report_party_statement', 'label' => 'كشف حساب مورد - عميل', 'icon' => '📋'],
                        ['r' => 'report_account_statement', 'label' => 'كشف حساب', 'icon' => '📋'],
                        ['r' => 'report_trial_balance', 'label' => 'ميزان المراجعة', 'icon' => '⚖'],
                        ['r' => 'report_trial_balance_detailed', 'label' => 'ميزان مراجعة تفصيلي', 'icon' => '⚖'],
                        ['r' => 'report_journal', 'label' => 'تقرير القيود', 'icon' => '📒'],
                        ['r' => 'report_income_statement_comprehensive', 'label' => 'الأرباح والخسائر', 'icon' => '📊'],
                        ['r' => 'report_income_statement', 'label' => 'قائمة الدخل', 'icon' => '📈'],
                        ['r' => 'report_balance_sheet', 'label' => 'الميزانية العمومية', 'icon' => '🏛'],
                    ],
                ],
                [
                    'id' => 'vat_reports',
                    'title' => 'تقارير الضريبة',
                    'items' => [
                        ['r' => 'report_tax_declaration', 'label' => 'الإقرار الضريبي', 'icon' => '📋'],
                        ['r' => 'report_tax_ar3', 'label' => 'تقرير الضريبة (أر/3)', 'icon' => '📄'],
                        ['r' => 'report_vat_net_payable', 'label' => 'أمانات ضريبة مبيعات', 'icon' => '🇯🇴'],
                        ['r' => 'report_invoice_tax', 'label' => 'ضريبة فواتير البيع', 'icon' => '🧾'],
                        ['r' => 'report_invoice_tax_purchase', 'label' => 'ضريبة فواتير الشراء', 'icon' => '📥'],
                        ['r' => 'report_vat_return_tax', 'label' => 'ضريبة مردود البيع', 'icon' => '↩'],
                        ['r' => 'report_vat_return_tax_purchase', 'label' => 'ضريبة مردود الشراء', 'icon' => '↩'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'hr',
            'title' => 'شؤون الموظفين',
            'subgroups' => [
                [
                    'id' => 'employees',
                    'title' => 'بيانات الموظف الاساسية',
                    'subgroups' => [
                        [
                            'id' => 'employee_screens',
                            'title' => 'شاشات الموظفين',
                            'items' => [
                                ['r' => 'hr_employees', 'label' => 'بيانات الموظف الاساسية', 'icon' => '👤'],
                            ],
                        ],
                        [
                            'id' => 'employee_reports',
                            'title' => 'تقارير الموظفين',
                            'items' => [
                                ['r' => 'report_hr_employees', 'label' => 'تقرير الموظفين', 'icon' => '📋'],
                                ['r' => 'report_hr_employees_by_department', 'label' => 'الموظفين حسب القسم', 'icon' => '🏛'],
                                ['r' => 'report_hr_employees_resigned', 'label' => 'الموظفين المستقيلين', 'icon' => '📤'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'employee_attendance',
                    'title' => 'بصمات الموظفين',
                    'subgroups' => [
                        [
                            'id' => 'attendance_screens',
                            'title' => 'شاشات البصمات',
                            'items' => [
                                ['r' => 'hr_employee_attendance', 'label' => 'بصمات الموظفين', 'icon' => '👆'],
                                ['r' => 'hr_attendance_sync_server', 'label' => 'مزامنة السيرفر (ZKT)', 'icon' => '🌐'],
                                ['r' => 'hr_attendance_sync_local', 'label' => 'مزامنة Windows (محلي)', 'icon' => '💻'],
                                ['r' => 'hr_employee_schedule', 'label' => 'تعريف دوام الموظف', 'icon' => '📅'],
                                ['r' => 'hr_attendance_settings', 'label' => 'إعدادات دوام الموظفين', 'icon' => '⚙'],
                            ],
                        ],
                        [
                            'id' => 'attendance_reports',
                            'title' => 'تقارير البصمات',
                            'items' => [
                                ['r' => 'report_hr_employee_attendance', 'label' => 'حركة دوام الموظفين', 'icon' => '🕐'],
                                ['r' => 'report_hr_att_punch_movements', 'label' => 'حركات البصمات (الكل)', 'icon' => '👆'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'departures',
                    'title' => 'المغادرات',
                    'subgroups' => [
                        [
                            'id' => 'departure_screens',
                            'title' => 'شاشات المغادرات',
                            'items' => [
                                ['r' => 'hr_departure_types', 'label' => 'أنواع المغادرات', 'icon' => '📋'],
                                ['r' => 'hr_employee_departures', 'label' => 'مغادرات الموظفين', 'icon' => '🚪'],
                            ],
                        ],
                        [
                            'id' => 'departure_reports',
                            'title' => 'تقارير المغادرات',
                            'items' => [
                                ['r' => 'report_hr_employee_departures', 'label' => 'تقرير المغادرات بين تاريخين', 'icon' => '📊'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'leaves',
                    'title' => 'الإجازات',
                    'subgroups' => [
                        [
                            'id' => 'leave_screens',
                            'title' => 'شاشات الإجازات',
                            'items' => [
                                ['r' => 'hr_leave_types', 'label' => 'إعدادات الإجازات', 'icon' => '📋'],
                                ['r' => 'hr_employee_leave_balances', 'label' => 'رصيد إجازات الموظفين', 'icon' => '📊'],
                                ['r' => 'hr_employee_leaves', 'label' => 'إدخال الإجازات', 'icon' => '🏖'],
                            ],
                        ],
                        [
                            'id' => 'leave_reports',
                            'title' => 'تقارير الإجازات',
                            'items' => [
                                ['r' => 'report_hr_employee_leaves', 'label' => 'تقرير الإجازات بين تاريخين', 'icon' => '📊'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'salaries',
                    'title' => 'الرواتب',
                    'subgroups' => [
                        [
                            'id' => 'salary_screens',
                            'title' => 'شاشات الرواتب',
                            'items' => [
                                ['r' => 'hr_salaries', 'label' => 'رواتب الموظفين', 'icon' => '💵'],
                                ['r' => 'hr_monthly_payroll_adjustments', 'label' => 'علاوات واقتطاعات شهرية', 'icon' => '📅'],
                                ['r' => 'hr_employee_advances', 'label' => 'سلف الموظفين', 'icon' => '💳'],
                                ['r' => 'hr_payroll_posting', 'label' => 'قيد الرواتب', 'icon' => '📋'],
                            ],
                        ],
                        [
                            'id' => 'salary_reports',
                            'title' => 'تقارير الرواتب',
                            'items' => [
                                ['r' => 'hr_payroll_month_report', 'label' => 'تقرير قيود الرواتب حسب الشهر', 'icon' => '🖨'],
                                ['r' => 'hr_payroll_dept_report', 'label' => 'كشف الرواتب للأقسام', 'icon' => '📑'],
                                ['r' => 'hr_payroll_ss_report', 'label' => 'كشف الضمان الاجتماعي', 'icon' => '🛡️'],
                                ['r' => 'hr_payroll_slip_report', 'label' => 'قسيمة الراتب', 'icon' => '🧾'],
                                ['r' => 'report_hr_employee_advances', 'label' => 'تقرير سلف الموظفين', 'icon' => '💳'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'overtime',
                    'title' => 'العمل الإضافي',
                    'subgroups' => [
                        [
                            'id' => 'overtime_screens',
                            'title' => 'شاشات العمل الإضافي',
                            'items' => [
                                ['r' => 'hr_overtime_settings', 'label' => 'إعدادات العمل الإضافي', 'icon' => '⚙'],
                                ['r' => 'hr_employee_overtime', 'label' => 'تسجيل ساعات العمل الإضافي', 'icon' => '⏱'],
                            ],
                        ],
                        [
                            'id' => 'overtime_reports',
                            'title' => 'تقارير العمل الإضافي',
                            'items' => [
                                ['r' => 'report_hr_employee_overtime', 'label' => 'تقرير العمل الإضافي', 'icon' => '⏱'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'salary_settings',
                    'title' => 'إعدادات الرواتب',
                    'items' => [
                        ['r' => 'hr_departments', 'label' => 'الأقسام', 'icon' => '🏛'],
                        ['r' => 'hr_job_titles', 'label' => 'المسميات الوظيفية', 'icon' => '💼'],
                        ['r' => 'hr_nationalities', 'label' => 'الجنسيات', 'icon' => '🌍'],
                        ['r' => 'hr_payroll_components', 'label' => 'إعداد العلاوات والاقتطاعات', 'icon' => '➕➖'],
                        ['r' => 'hr_salary_banks', 'label' => 'البنوك', 'icon' => '🏦'],
                        ['r' => 'hr_employee_bank_link', 'label' => 'ربط إعدادات البنك', 'icon' => '🔗'],
                        ['r' => 'hr_social_security_rates', 'label' => 'نسب الضمان الاجتماعي', 'icon' => '📊'],
                        ['r' => 'hr_income_tax_settings', 'label' => 'إعدادات ضريبة الدخل', 'icon' => '🧮'],
                        ['r' => 'hr_social_security', 'label' => 'قيود الضمان', 'icon' => '🛡'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'system',
            'title' => 'النظام',
            'subgroups' => [
                [
                    'id' => 'users',
                    'title' => 'المستخدمون والصلاحيات',
                    'items' => [
                        ['r' => 'users', 'label' => 'المستخدمون', 'icon' => '👥'],
                        ['r' => 'groups', 'label' => 'المجموعات', 'icon' => '📁'],
                        ['r' => 'permissions', 'label' => 'الصلاحيات', 'icon' => '🔐'],
                    ],
                ],
                [
                    'id' => 'settings',
                    'title' => 'إعدادات النظام',
                    'items' => [
                        ['r' => 'settings', 'label' => 'الإعدادات', 'icon' => '⚙'],
                        ['r' => 'dashboard_accounts_settings', 'label' => 'حسابات الشاشة الرئيسية', 'icon' => '⌂'],
                        ['r' => 'system_backup', 'label' => 'النسخ الاحتياطي', 'icon' => '💾'],
                        ['r' => 'tax_rates_settings', 'label' => 'معدّلات الضريبة', 'icon' => '%'],
                        ['r' => 'einvoice_settings', 'label' => 'إعدادات الفوترة', 'icon' => '🧾'],
                        ['r' => 'report_audit_log', 'label' => 'حركات التعديل', 'icon' => '📝'],
                        ['r' => 'sales_invoice_gps', 'label' => 'مواقع فواتير البيع', 'icon' => '📍'],
                        ['r' => 'user_gps_locations', 'label' => 'مواقع المستخدمين', 'icon' => '🗺'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'mobile',
            'title' => 'تطبيق الهاتف',
            'subgroups' => [
                [
                    'id' => 'mobile_screens',
                    'title' => 'شاشات الهاتف',
                    'flat' => true,
                    'items' => [
                        ['r' => 'm_home', 'label' => 'الرئيسية', 'icon' => '📱'],
                        ['r' => 'm_sales_invoices', 'label' => 'فواتير المبيعات', 'icon' => '🧾'],
                        ['r' => 'm_party_statement', 'label' => 'كشف حساب', 'icon' => '📋'],
                        ['r' => 'm_receipt', 'label' => 'سند قبض', 'icon' => '⬆'],
                        ['r' => 'm_sales_returns', 'label' => 'مرتجع مبيعات', 'icon' => '↩'],
                        ['r' => 'm_user_gps_locations', 'label' => 'مواقع المستخدمين', 'icon' => '📍'],
                        ['r' => 'm_rep_load', 'label' => 'تحميل عهدة', 'icon' => '📦'],
                        ['r' => 'm_rep_return', 'label' => 'إرجاع عهدة', 'icon' => '↩'],
                        ['r' => 'm_rep_stock', 'label' => 'رصيد العهدة', 'icon' => '📊'],
                    ],
                ],
            ],
        ],
    ],
];
