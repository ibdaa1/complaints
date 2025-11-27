<?php
// shjfcs/complaints/uploads_complaints.php
// رفع ملف مرفق للشكاوى إلى uploads/ (حد 3MiB) -> يعيد JSON { success, path }
// يحمي من أنواع خطرة ويعطي اسم آمن

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode(['success'=>false,'message'=>'غير مصرح'], JSON_UNESCAPED_UNICODE); exit;
}

$MAX_BYTES = 3 * 1024 * 1024;
if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success'=>false,'message'=>'الرجاء اختيار ملف'], JSON_UNESCAPED_UNICODE); exit;
}
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'message'=>'خطأ خلال الرفع'], JSON_UNESCAPED_UNICODE); exit;
}
if ($f['size'] > $MAX_BYTES) {
    echo json_encode(['success'=>false,'message'=>'حجم الملف أكبر من 3 ميجابايت']), JSON_UNESCAPED_UNICODE; exit;
}

// simple allowed extensions (pdf, jpg, jpeg, png)
$allowed_ext = ['pdf','jpg','jpeg','png'];
$orig = basename($f['name']);
$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_ext)) {
    echo json_encode(['success'=>false,'message'=>'نوع الملف غير مسموح'], JSON_UNESCAPED_UNICODE); exit;
}

$safeBase = preg_replace('/[^A-Za-z0-9_\-]/u', '_', pathinfo($orig, PATHINFO_FILENAME));
$fname = $safeBase . '_' . time() . '.' . $ext;
$dir = __DIR__ . '/uploads';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$path = $dir . '/' . $fname;
if (!move_uploaded_file($f['tmp_name'], $path)) {
    echo json_encode(['success'=>false,'message'=>'فشل حفظ الملف'], JSON_UNESCAPED_UNICODE); exit;
}
@chmod($path, 0644);
// return relative url
$url = 'complaints/uploads/' . rawurlencode($fname);
echo json_encode(['success'=>true,'path' => $url, 'filename' => $fname], JSON_UNESCAPED_UNICODE);
exit;
?>