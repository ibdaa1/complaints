<?php
// shjfcs/complaints/get_inspections.php
// يعيد أحدث تفتيش لكل منشأة (نوع التفتيش = شكوى) مع تجميع الإجراءات (action_name/action_number)
// يمكن استدعاؤه مع ?facility_unique_id=... أو ?license_no=...
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

$facility_unq = $_GET['facility_unique_id'] ?? '';
$license_no = $_GET['license_no'] ?? '';

// choose matching facilities set
$params = []; $types = '';
$where = "1=1";
if ($facility_unq) { $where .= " AND i.facility_unique_id = ?"; $types .= 's'; $params[] = $facility_unq; }
elseif ($license_no) {
    // join via establishments
    $where .= " AND i.facility_unique_id IN (SELECT unique_id FROM establishments WHERE license_no = ?)";
    $types .= 's'; $params[] = $license_no;
}

// only inspection_type = 'شكوى' or 'شكوي' (cover both spellings)
$where .= " AND (i.inspection_type = 'شكوى' OR i.inspection_type = 'شكوي')";

$sql = "
SELECT i.facility_unique_id, i.inspection_id, i.inspection_date, i.inspection_type, i.campaign_name,
       i.inspector_user_id, i.approval_status, i.notes,
       -- aggregate actions for this inspection
       (SELECT GROUP_CONCAT(CONCAT(action_name, ' (#', action_number, ')') SEPARATOR ' || ') FROM tbl_inspection_actions ia WHERE ia.inspection_id = i.inspection_id) AS actions_concat
FROM tbl_inspections i
WHERE $where
ORDER BY i.inspection_date DESC, i.inspection_id DESC
LIMIT 200
";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success'=>false,'message'=>'DB prepare failed: ' . $conn->error], JSON_UNESCAPED_UNICODE); exit;
}
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($r = $res->fetch_assoc()) $data[] = $r;
$stmt->close();
echo json_encode(['success'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
exit;
?>