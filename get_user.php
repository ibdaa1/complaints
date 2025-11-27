<?php
// يعيد بيانات المستخدم من الجلسة إلى الواجهة الأمامية
header('Content-Type: application/json; charset=utf-8');
session_start();
$user = $_SESSION['user'] ?? null;
if (!$user) {
    echo json_encode(['success'=>false,'error'=>'غير مسجل الدخول']);
    exit;
}
echo json_encode(['success'=>true,'user'=>$user], JSON_UNESCAPED_UNICODE);
?>