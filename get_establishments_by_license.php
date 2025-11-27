<?php
// shjfcs/complaints/get_establishments_by_license.php
// استرجاع قائمة المنشآت (unique_id, facility_name) بحسب رقم الترخيص (license_no).
// إصلاح: لا نطلب عمود غير موجود (facility_unq_id) لتجنب خطأ Unknown column.
// حفظ بصيغة UTF-8 بدون BOM.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

$license = isset($_GET['license_no']) ? trim($_GET['license_no']) : '';
if ($license === '') {
    echo json_encode(['success' => false, 'message' => 'license_no مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

// optional: sanity check that table exists
$check = $conn->query("SHOW TABLES LIKE 'establishments'");
if (!$check || $check->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'جدول المنشآت غير موجود في قاعدة البيانات'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Use only columns that are known to exist: unique_id, facility_name
$sql = "SELECT unique_id, facility_name FROM establishments WHERE license_no = ? ORDER BY facility_name ASC";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'خطأ في تحضير الاستعلام: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    exit;
}
$stmt->bind_param('s', $license);
$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($r = $res->fetch_assoc()) {
    // ensure consistent field names in response
    $data[] = [
        'unique_id' => $r['unique_id'],
        'facility_name' => $r['facility_name']
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
exit;
?>