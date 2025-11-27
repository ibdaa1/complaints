<?php
// complaint_products.php
// Robust handler for complaint products: list, create, bulk_create, update, delete
// - Place in public_html/shjfcs/complaints/complaint_products.php
// - Expects ../db.php providing $conn (mysqli) and session for auth ($_SESSION['user']['EmpID'])
// - bulk_create expects POST with JSON body: { "complaint_id": 1, "products": [ {...}, ... ] }
// - All requests should use credentials:'same-origin' from the browser

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'DB connection missing']);
    exit;
}
$conn->set_charset('utf8mb4');

// debug log helper (optional)
function dbg($msg) {
    @file_put_contents(__DIR__ . '/complaint_products_debug.log', '['.date('c').'] '.$msg.PHP_EOL, FILE_APPEND | LOCK_EX);
}

// parse raw JSON body if any
$raw = file_get_contents('php://input');
$bodyJson = null;
if ($raw) {
    $bodyJson = @json_decode($raw, true);
    if ($raw && $bodyJson === null) {
        // not JSON or empty; that's OK for form posts
    }
}

$action = $_REQUEST['action'] ?? 'list';

// helper to get param from JSON body, POST or GET
function getp($key, $default = null) {
    global $bodyJson;
    if (is_array($bodyJson) && array_key_exists($key, $bodyJson)) return $bodyJson[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    return $default;
}

function json_ok($data = []) { echo json_encode(array_merge(['success'=>true], $data), JSON_UNESCAPED_UNICODE); exit; }
function json_err($msg, $code=400) { http_response_code($code); echo json_encode(['success'=>false,'message'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

// Optional: log request (uncomment if needed)
// dbg("ACTION: $action; METHOD: " . $_SERVER['REQUEST_METHOD'] . "; RAW: " . substr($raw,0,1000));

$emp = isset($_SESSION['user']['EmpID']) ? (int)$_SESSION['user']['EmpID'] : null;

// --- LIST ---
if ($action === 'list') {
    $cid = (int) getp('complaint_id', 0);
    if ($cid <= 0) json_err('complaint_id مطلوب للقائمة', 400);
    $stmt = $conn->prepare("SELECT id, complaint_id, product_name, brand_name, sample_type, country_of_origin, production_date, expiry_date, weight, batch_number, lab_result, notes, created_by_empid, updated_by_empid, created_at, updated_at FROM complaint_products WHERE complaint_id = ? ORDER BY id ASC");
    if (!$stmt) { dbg("prepare list failed: ".$conn->error); json_err('DB prepare failed', 500); }
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    json_ok(['data' => $rows]);
}

// For write operations require auth
if (in_array($action, ['create','bulk_create','update','delete']) && !$emp) {
    json_err('غير مصرح - الرجاء تسجيل الدخول', 401);
}

// --- CREATE single product (form POST) ---
if ($action === 'create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
    $cid = (int) getp('complaint_id', 0);
    $pname = trim((string) getp('product_name', ''));
    if ($cid <= 0) json_err('complaint_id مطلوب', 400);
    if ($pname === '') json_err('product_name مطلوب', 400);

    $brand = getp('brand_name', null);
    $sample_type = getp('sample_type', null);
    $country = getp('country_of_origin', null);
    $production_date = getp('production_date', null);
    $expiry_date = getp('expiry_date', null);
    $weight = getp('weight', null);
    $batch_number = getp('batch_number', null);
    $lab_result = getp('lab_result', null);
    $notes = getp('notes', null);

    $sql = "INSERT INTO complaint_products (complaint_id, product_name, brand_name, sample_type, country_of_origin, production_date, expiry_date, weight, batch_number, lab_result, notes, created_by_empid, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { dbg("prepare insert failed: ".$conn->error); json_err('DB prepare failed', 500); }

    // created_by must be a variable (for bind_param)
    $created_by = $emp;
    $stmt->bind_param('issssssssssi', $cid, $pname, $brand, $sample_type, $country, $production_date, $expiry_date, $weight, $batch_number, $lab_result, $notes, $created_by);
    if (!$stmt->execute()) { dbg("execute insert failed: ".$stmt->error); $stmt->close(); json_err('DB insert failed: ' . $stmt->error, 500); }
    $newId = $stmt->insert_id;
    $stmt->close();
    dbg("Inserted product id=$newId for complaint_id=$cid by emp=$created_by");
    json_ok(['id' => $newId, 'message' => 'تمت إضافة المنتج']);
}

// --- BULK CREATE (expects POST JSON body) ---
if ($action === 'bulk_create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
    if (!is_array($bodyJson)) json_err('Body must be JSON', 400);
    $cid = (int) ($bodyJson['complaint_id'] ?? 0);
    $products = $bodyJson['products'] ?? null;
    if ($cid <= 0) json_err('complaint_id مطلوب (bulk_create)', 400);
    if (!is_array($products) || empty($products)) json_err('products مطلوب كمصفوفة', 400);

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO complaint_products (complaint_id, product_name, brand_name, sample_type, country_of_origin, production_date, expiry_date, weight, batch_number, lab_result, notes, created_by_empid, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('DB prepare failed: ' . $conn->error);
        foreach ($products as $p) {
            $pname = trim((string)($p['product_name'] ?? ''));
            if ($pname === '') throw new Exception('product_name مفقود في أحد السجلات');
            $brand = $p['brand_name'] ?? null;
            $sample_type = $p['sample_type'] ?? null;
            $country = $p['country_of_origin'] ?? null;
            $production_date = $p['production_date'] ?? null;
            $expiry_date = $p['expiry_date'] ?? null;
            $weight = $p['weight'] ?? null;
            $batch_number = $p['batch_number'] ?? null;
            $lab_result = $p['lab_result'] ?? null;
            $notes = $p['notes'] ?? null;

            // created_by must be variable
            $created_by = $emp;

            // bind variables (must be variables, not expressions)
            $stmt->bind_param('issssssssssi', $cid, $pname, $brand, $sample_type, $country, $production_date, $expiry_date, $weight, $batch_number, $lab_result, $notes, $created_by);
            if (!$stmt->execute()) throw new Exception('Insert failed: ' . $stmt->error);
        }
        $stmt->close();
        $conn->commit();
        dbg("bulk_create success: complaint_id=$cid count=" . count($products));
        json_ok(['message' => 'تم حفظ المنتجات بنجاح']);
    } catch (Throwable $e) {
        $conn->rollback();
        dbg("bulk_create failed: " . $e->getMessage());
        json_err('فشل حفظ المنتجات الدفعي: ' . $e->getMessage(), 500);
    }
}

// --- UPDATE ---
if ($action === 'update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
    $id = (int) getp('id', 0);
    if ($id <= 0) json_err('id مطلوب للتحديث', 400);
    $allowed = ['product_name','brand_name','sample_type','country_of_origin','production_date','expiry_date','weight','batch_number','lab_result','notes'];
    $set = []; $params = []; $types = '';
    foreach ($allowed as $f) {
        if (isset($_POST[$f]) || (is_array($bodyJson) && array_key_exists($f, $bodyJson))) {
            $set[] = "`$f` = ?";
            $params[] = getp($f);
            $types .= 's';
        }
    }
    if (empty($set)) json_err('لا توجد حقول للتحديث', 400);
    $set[] = "updated_by_empid = ?";
    $params[] = $emp; $types .= 'i';
    $params[] = $id; $types .= 'i';
    $sql = "UPDATE complaint_products SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { dbg("prepare update failed: ".$conn->error); json_err('DB prepare failed', 500); }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { dbg("execute update failed: ".$stmt->error); $stmt->close(); json_err('DB update failed: '.$stmt->error, 500); }
    $stmt->close();
    json_ok(['message' => 'تم التحديث']);
}

// --- DELETE ---
if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
    $id = (int) getp('id', 0);
    if ($id <= 0) json_err('id مطلوب للحذف', 400);
    $stmt = $conn->prepare("DELETE FROM complaint_products WHERE id = ? LIMIT 1");
    if (!$stmt) { dbg("prepare delete failed: ".$conn->error); json_err('DB prepare failed', 500); }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) { dbg("execute delete failed: ".$stmt->error); $stmt->close(); json_err('DB delete failed: '.$stmt->error, 500); }
    $stmt->close();
    dbg("Deleted product id=".$id);
    json_ok(['message' => 'تم الحذف']);
}

dbg("Unknown action: " . $action);
json_err('action غير معروف: ' . $action, 400);
?>