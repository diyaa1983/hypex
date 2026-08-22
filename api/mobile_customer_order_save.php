<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/warehouse_access.php');
require_once app_path('includes/sal_rep_route.php');
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in() || !mobile_can_access_customer_order_api() || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
$body=json_decode((string)file_get_contents('php://input'),true); $body=is_array($body)?$body:$_POST;
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null))) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'انتهت صلاحية الجلسة.'],JSON_UNESCAPED_UNICODE); exit; }
try {
 $pdo=db(); sal_customer_order_ensure_schema($pdo); $uid=(int)(current_user()['id']??0); $rep=crm_sales_rep_id_for_user($pdo,$uid);
 if ($rep===null && !user_is_system_admin()) throw new RuntimeException('حسابك غير مربوط بمندوب مبيعات.');
 $id=(int)($body['id']??0);
 if ($id>0) { $old=sal_customer_order_fetch($pdo,$id); if (!$old || ($rep!==null && (int)$old['sales_rep_id']!==$rep)) throw new RuntimeException('الطلب غير موجود.'); }
 $customerId=(int)($body['customer_id']??0);
 if ($rep!==null && $customerId>0 && !crm_customer_is_linked_to_sales_rep($pdo,$customerId,$rep)) {
     throw new RuntimeException('هذا العميل غير مربوط بمندوبك.');
 }
 sal_rep_visit_assert_allowed($pdo, $customerId, $body, $rep);
 $visitLineId=(int)($body['visit_route_line_id']??0);
 if ($id < 1 && $rep !== null) {
     if ($visitLineId < 1) throw new RuntimeException('يجب تسجيل الدخول عند العميل قبل إنشاء الطلبية.');
     $vst=$pdo->prepare(
         'SELECT l.id FROM sal_rep_route_line l
          INNER JOIN sal_rep_route r ON r.id=l.route_id
          WHERE l.id=? AND r.sales_rep_id=? AND l.customer_id=?
            AND r.route_date=CURDATE() AND l.visit_checkin_at IS NOT NULL
            AND l.visit_checkout_at IS NULL LIMIT 1'
     );
     $vst->execute([$visitLineId,$rep,$customerId]);
     if (!(int)$vst->fetchColumn()) {
         throw new RuntimeException('لا توجد زيارة مفتوحة لهذا العميل. سجّل الدخول أولاً.');
     }
 }
 $warehouse=(int)($body['warehouse_id']??0); if (!wh_access_can_issue($pdo,$warehouse)) throw new RuntimeException(wh_access_deny_issue_message());
 $saved=sal_customer_order_save($pdo,$body,is_array($body['lines']??null)?$body['lines']:[],$uid,$id>0?null:$rep);
 if ($visitLineId > 0) {
     $pdo->prepare(
         'UPDATE sal_customer_order SET visit_route_line_id=?
          WHERE id=? AND customer_id=?'
     )->execute([$visitLineId,$saved,$customerId]);
 }
 $order=sal_customer_order_fetch($pdo,$saved);
 require_once app_path('includes/header_check_notifications.php');
 require_once app_path('includes/document_header.php');
 header_check_notifications_invalidate_cache();
 $order = document_header_attach_brand(is_array($order) ? $order : [], $pdo);
 echo json_encode([
     'ok'=>true,
     'order_id'=>$saved,
     'order_no'=>$order['order_no']??'',
     'order'=>$order,
     'message'=>'تم حفظ الطلب. يظهر في كشف الحساب بعد اعتماد الإدارة.',
 ],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
