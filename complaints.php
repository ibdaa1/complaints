<?php
// public_html/shjfcs/complaints/complaints.php
// Single-file complaints app + API (search, create, update, delete, file upload, product CRUD).
// Modified: create/update/list/get/delete use ALL complaint fields from DESCRIBE output.
// - db.php in parent folder must define $conn (mysqli) and set correct charset.
// - session-based auth: $_SESSION['user']['EmpID'] used for write operations.
// - Save file as UTF-8 without BOM.

session_start();
require_once __DIR__ . '/../db.php'; // expects $conn (mysqli)
$conn->set_charset('utf8mb4');

// Helper: send JSON
function json_ok($data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// Utility to build and bind mysqli params dynamically
function stmt_bind_params_dyn(mysqli_stmt $stmt, string $types, array $params) {
    // mysqli_stmt::bind_param requires references
    $refs = [];
    foreach ($params as $k => $v) $refs[$k] = &$params[$k];
    array_unshift($refs, $types);
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

// Read action param
$action = $_REQUEST['action'] ?? 'page';

// If action != page -> treat as API and return JSON
if ($action !== 'page' && $action !== 'home') {
    // Route API requests
    // --- API: establishments by license (search) ---
    if ($action === 'establishments') {
        $license = trim($_GET['license_no'] ?? '');
        if ($license === '') json_err('license_no مطلوب', 400);
        $stmt = $conn->prepare("SELECT unique_id, facility_name, license_no, area, unit FROM establishments WHERE license_no = ? ORDER BY facility_name ASC");
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        $stmt->bind_param('s', $license);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        json_ok(['data' => $data]);
    }

    // --- API: complaints list (support filters) ---
    if ($action === 'list') {
        // allow optional filters via GET (match substrings)
        $filters = [
            'establishment_unique_id','license_no','facility_name','complainant_name','complaint_subject','complaint_status',
            'created_by_empid','supervisor_empid','manager_empid','complaint_category'
        ];
        $where = [];
        $params = [];
        $types = '';
        foreach ($filters as $f) {
            if (isset($_GET[$f]) && $_GET[$f] !== '') {
                // If numeric field (empids) and looks numeric, match exact, otherwise LIKE
                if (in_array($f, ['created_by_empid','supervisor_empid','manager_empid'])) {
                    $where[] = "`$f` = ?";
                    $params[] = (int)$_GET[$f];
                    $types .= 'i';
                } else {
                    $where[] = "`$f` LIKE ?";
                    $params[] = '%' . $_GET[$f] . '%';
                    $types .= 's';
                }
            }
        }
        $sql = "SELECT * FROM complaints";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY received_datetime DESC LIMIT 1000";
        $stmt = $conn->prepare($sql);
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        if ($params) {
            stmt_bind_params_dyn($stmt, $types, $params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        json_ok(['data' => $rows]);
    }

    // --- API: get single complaint ---
    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_err('id مطلوب', 400);
        $stmt = $conn->prepare("SELECT * FROM complaints WHERE id = ? LIMIT 1");
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$r) json_err('Not found', 404);
        json_ok(['data' => $r]);
    }

    // --- API: create complaint (accepts all fields) ---
    if ($action === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
        $emp = $_SESSION['user']['EmpID'] ?? null;
        if (!$emp) json_err('غير مصرح - الرجاء تسجيل الدخول', 401);

        // All columns except id, created_at, updated_at, updated_by_empid are acceptable from POST
        $cols = [
            'establishment_unique_id','received_datetime','complaints_source','hotline_complaint_number','complainant_name',
            'complainant_phone','complainant_statement','inspector_followup','sample_status','complainant_contact_action',
            'inspector_received_datetime','complaint_subject','site_manager_statement','supervisor_empid','manager_empid',
            'supervisor_comment','complaint_category','complaint_status','response_speed','section_actions','food_poisoning_suspect',
            'division_head_comment','taken_actions','followup_datetime','closed_datetime','attachment_url'
        ];
        $placeholders = [];
        $values = [];
        $types = '';
        foreach ($cols as $c) {
            $v = $_POST[$c] ?? null;
            // cast ints for empids if present
            if (in_array($c, ['supervisor_empid','manager_empid'])) {
                if ($v === null || $v === '') $v = null;
                else $v = (int)$v;
            }
            $placeholders[] = '?';
            $values[] = $v;
            $types .= (in_array($c, ['supervisor_empid','manager_empid']) ? 'i' : 's');
        }
        // add created_by_empid
        $cols[] = 'created_by_empid';
        $placeholders[] = '?';
        $values[] = $emp;
        $types .= 'i';

        $sql = "INSERT INTO complaints (" . implode(',', $cols) . ", created_at) VALUES (" . implode(',', $placeholders) . ", NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);

        // bind params dynamically - convert nulls to null strings acceptable; for mysqli, nulls must be bound as null via variables
        // Ensure all params are variables
        $bindParams = [];
        foreach ($values as $k => $v) {
            // for null, set null
            $bindParams[] = $v;
        }
        stmt_bind_params_dyn($stmt, $types, $bindParams);
        // mysqli doesn't accept binding nulls differently; but passing null is OK

        if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); json_err('DB insert failed: ' . $e, 500); }
        $newId = $stmt->insert_id; $stmt->close();
        json_ok(['id' => $newId, 'message' => 'تم الإنشاء']);
    }

    // --- API: update complaint (accepts all fields dynamically) ---
    if ($action === 'update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
        $emp = $_SESSION['user']['EmpID'] ?? null;
        if (!$emp) json_err('غير مصرح - الرجاء تسجيل الدخول', 401);

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_err('id مطلوب', 400);

        // Allowed updatable fields (all except id, created_by_empid, created_at)
        $allowed = [
            'establishment_unique_id','received_datetime','complaints_source','hotline_complaint_number','complainant_name',
            'complainant_phone','complainant_statement','inspector_followup','sample_status','complainant_contact_action',
            'inspector_received_datetime','complaint_subject','site_manager_statement','supervisor_empid','manager_empid',
            'supervisor_comment','complaint_category','complaint_status','response_speed','section_actions','food_poisoning_suspect',
            'division_head_comment','taken_actions','followup_datetime','closed_datetime','attachment_url'
        ];
        $set = []; $params = []; $types = '';
        foreach ($allowed as $f) {
            if (array_key_exists($f, $_POST)) {
                $set[] = "`$f` = ?";
                // cast empid fields to int if provided non-empty
                if (in_array($f, ['supervisor_empid','manager_empid'])) {
                    $val = $_POST[$f] === '' ? null : (int)$_POST[$f];
                    $params[] = $val;
                    $types .= 'i';
                } else {
                    $params[] = $_POST[$f];
                    $types .= 's';
                }
            }
        }
        if (empty($set)) json_err('لا توجد حقول للتحديث', 400);

        // append updated_by_empid
        $set[] = "updated_by_empid = ?";
        $params[] = $emp;
        $types .= 'i';

        // add id parameter
        $params[] = $id;
        $types .= 'i';

        $sql = "UPDATE complaints SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        // bind dynamic
        stmt_bind_params_dyn($stmt, $types, $params);
        if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); json_err('DB update failed: ' . $e, 500); }
        $stmt->close();
        json_ok(['message' => 'تم التحديث']);
    }

    // --- API: delete complaint ---
    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
        $emp = $_SESSION['user']['EmpID'] ?? null;
        if (!$emp) json_err('غير مصرح - الرجاء تسجيل الدخول', 401);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_err('id مطلوب', 400);

        // delete products
        $stmt = $conn->prepare("DELETE FROM complaint_products WHERE complaint_id = ?");
        if ($stmt) { $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close(); }

        // delete attachment file
        $st = $conn->prepare("SELECT attachment_url FROM complaints WHERE id = ? LIMIT 1");
        if ($st) { $st->bind_param('i', $id); $st->execute(); $row = $st->get_result()->fetch_assoc(); $st->close(); if (!empty($row['attachment_url'])) { $p = __DIR__ . '/' . basename($row['attachment_url']); if (is_file($p)) @unlink($p); } }

        $stmt = $conn->prepare("DELETE FROM complaints WHERE id = ? LIMIT 1");
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); json_err('DB delete failed: ' . $e, 500); }
        $stmt->close();
        json_ok(['message' => 'تم الحذف']);
    }

    // --- API: upload attachment (unchanged) ---
    if ($action === 'upload') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
        $emp = $_SESSION['user']['EmpID'] ?? null;
        if (!$emp) json_err('غير مصرح', 401);
        if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) json_err('اختر ملفاً', 400);
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) json_err('خطأ في الرفع', 500);
        $MAX = 10 * 1024 * 1024;
        if ($f['size'] > $MAX) json_err('حجم الملف أكبر من 10 ميجا', 400);
        $allowed = ['pdf','jpg','jpeg','png'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) json_err('نوع الملف غير مسموح', 400);
        $base = preg_replace('/[^A-Za-z0-9_\-]/u', '_', pathinfo($f['name'], PATHINFO_FILENAME));
        $fname = $base . '_' . time() . '.' . $ext;
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $dst = $dir . '/' . $fname;
        if (!move_uploaded_file($f['tmp_name'], $dst)) json_err('فشل حفظ الملف', 500);
        @chmod($dst, 0644);
        $rel = 'complaints/uploads/' . rawurlencode($fname);
        json_ok(['path' => $rel, 'filename' => $fname]);
    }

    // --- API: products endpoints (re-use existing code paths but names adjusted) ---
    if ($action === 'products_list') {
        $cid = (int)($_GET['complaint_id'] ?? 0);
        if ($cid <= 0) json_err('complaint_id مطلوب', 400);
        $stmt = $conn->prepare("SELECT * FROM complaint_products WHERE complaint_id = ? ORDER BY id ASC");
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        json_ok(['data' => $rows]);
    }
    if ($action === 'products_create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
        $emp = $_SESSION['user']['EmpID'] ?? null;
        if (!$emp) json_err('غير مصرح', 401);
        $cid = (int)($_POST['complaint_id'] ?? 0); if ($cid<=0) json_err('complaint_id مطلوب',400);
        $pname = trim($_POST['product_name'] ?? '');
        if ($pname === '') json_err('product_name مطلوب',400);
        $brand = $_POST['brand_name'] ?? null;
        $stype = $_POST['sample_type'] ?? null;
        $country = $_POST['country_of_origin'] ?? null;
        $prod_dt = $_POST['production_date'] ?? null;
        $exp_dt = $_POST['expiry_date'] ?? null;
        $weight = $_POST['weight'] ?? null;
        $batch = $_POST['batch_number'] ?? null;
        $lab = $_POST['lab_result'] ?? null;
        $notes = $_POST['notes'] ?? null;
        $sql = "INSERT INTO complaint_products (complaint_id, product_name, brand_name, sample_type, country_of_origin, production_date, expiry_date, weight, batch_number, lab_result, notes, created_by_empid, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        $stmt->bind_param('isssssssssss', $cid, $pname, $brand, $stype, $country, $prod_dt, $exp_dt, $weight, $batch, $lab, $notes, $emp);
        if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); json_err('DB insert failed: ' . $e, 500); }
        $newId = $stmt->insert_id; $stmt->close();
        json_ok(['id' => $newId, 'message' => 'تمت إضافة المنتج']);
    }
    if ($action === 'products_delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Use POST', 405);
        $emp = $_SESSION['user']['EmpID'] ?? null;
        if (!$emp) json_err('غير مصرح', 401);
        $id = (int)($_POST['id'] ?? 0); if ($id<=0) json_err('id مطلوب',400);
        $stmt = $conn->prepare("DELETE FROM complaint_products WHERE id = ? LIMIT 1");
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); json_err('DB delete failed: ' . $e, 500); }
        $stmt->close();
        json_ok(['message' => 'تم الحذف']);
    }

    // Unknown API action
    json_err('Invalid action', 400);
}

// If we reach here, action is 'page' or 'home' => render HTML UI.
// Serve HTML (this section uses same file for AJAX)
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ar">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>الشكاوى — إدارة (محدث)</title>
<style>
body{font-family:Arial, "Noto Naskh Arabic",sans-serif;direction:rtl;padding:12px}
.card{background:#fafafa;padding:12px;border:1px solid #eee;border-radius:8px;margin-bottom:12px}
label{display:block;margin-top:8px;font-weight:700}
input,select,textarea{width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box}
button{padding:8px 12px;background:#2a5b2a;color:#fff;border:0;border-radius:6px;cursor:pointer}
.secondary{background:transparent;color:#2a5b2a;border:1px solid #ddd}
.small{font-size:13px;color:#666}
.table-wrap{overflow:auto;margin-top:12px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #eee;padding:8px;text-align:right}
th{background:#f6f6f6}
.debug{background:#f4f4f4;padding:8px;border-radius:6px;white-space:pre-wrap;max-height:240px;overflow:auto}
</style>
</head>
<body>
<div class="card">
  <h2>تسجيل/إدارة الشكاوى (نموذج كامل)</h2>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <div style="flex:1">
      <label>بحث منشأة (رقم الترخيص)</label>
      <div style="display:flex;gap:8px">
        <input id="license_no" placeholder="أدخل رقم الترخيص">
        <button id="btnSearch" class="secondary" type="button">بحث</button>
      </div>
      <div id="estList" class="small"></div>
    </div>
    <div style="width:220px">
      <label>تحديث القائمة</label>
      <button id="btnReload">تحديث</button>
    </div>
  </div>
</div>

<div class="card">
  <form id="form" onsubmit="return false;">
    <input type="hidden" id="id">
    <label>unique_id (المنشأة)</label><input id="establishment_unique_id">
    <label>رقم الترخيص</label><input id="license_no_display">
    <label>اسم المنشأة</label><input id="facility_name">

    <label>تاريخ الاستلام</label><input id="received_datetime" type="datetime-local" required>
    <label>مصدر الشكوى</label><select id="complaints_source"></select>
    <label>رقم الخط الساخن</label><input id="hotline_complaint_number">
    <label>اسم الشاكي</label><input id="complainant_name">
    <label>هاتف الشاكي</label><input id="complainant_phone">
    <label>بيان الشاكي</label><textarea id="complainant_statement"></textarea>
    <label>موضوع الشكوى</label><textarea id="complaint_subject"></textarea>
    <label>إجراءات المفتش</label><textarea id="inspector_followup"></textarea>
    <label>حالة العينة</label><select id="sample_status"></select>
    <label>إجراء تواصل مع الشاكي</label><select id="complainant_contact_action"></select>
    <label>تاريخ استلام المفتش</label><input id="inspector_received_datetime" type="datetime-local">

    <label>بيان مدير الموقع</label><textarea id="site_manager_statement"></textarea>

    <label>السوبرفايزر EmpID</label><input id="supervisor_empid" placeholder="EmpID أو اترك فارغا">
    <label>السوبرفايزر تعليق</label><textarea id="supervisor_comment"></textarea>
    <label>تاريخ متابعة المشرف</label><input id="followup_datetime" type="datetime-local">

    <label>المدير EmpID</label><input id="manager_empid" placeholder="EmpID أو اترك فارغا">
    <label>تعليق المدير</label><textarea id="division_head_comment"></textarea>
    <label>تاريخ الإغلاق</label><input id="closed_datetime" type="datetime-local">

    <label>تصنيف الشكوى</label><select id="complaint_category"></select>
    <label>حالة الشكوى</label><select id="complaint_status"></select>
    <label>سرعة الاستجابة</label><input id="response_speed" readonly>

    <label>إجراءات القسم</label><textarea id="section_actions"></textarea>
    <label>مشتبه تسمم غذائى</label><input id="food_poisoning_suspect">
    <label>تعليقات قسم</label><textarea id="taken_actions"></textarea>

    <label>المرفق</label><input type="file" id="attachment_file">
    <div id="attachmentPath" class="small"></div>

    <div style="margin-top:8px;display:flex;gap:8px">
      <button id="saveBtn" type="button">حفظ (إنشاء)</button>
      <button id="updateBtn" type="button" class="secondary">حفظ التعديلات</button>
      <button id="deleteBtn" type="button" class="secondary" style="background:#d32f2f">حذف</button>
      <button id="btnSignSupervisor" type="button" class="secondary">توقيع السوبرفايزر (من الجلسة)</button>
      <button id="btnSignManager" type="button" class="secondary">توقيع المدير (من الجلسة)</button>
    </div>
    <div id="msg" class="small" style="margin-top:8px"></div>
  </form>
</div>

<div class="card">
  <h3>قائمة الشكاوى</h3>
  <div class="small" style="margin-bottom:8px">فلترة: <input id="searchAll" placeholder="بحث شامل"> <button id="btnApply" class="secondary">تطبيق</button> <button id="btnClear" class="secondary">مسح</button></div>
  <div id="list" class="table-wrap"></div>
  <pre id="raw" class="debug"></pre>
</div>

<script>
// Simple client side to call our APIs (this page)
const api = (params={}, method='GET', isJson=false) => {
  let url = '<?php echo basename(__FILE__); ?>';
  if (method === 'GET') {
    if (Object.keys(params).length) url += '?' + new URLSearchParams(params).toString();
    return fetch(url, { credentials: 'same-origin' }).then(r => r.json());
  } else {
    const opts = { method:'POST', credentials: 'same-origin' };
    opts.body = new URLSearchParams(params);
    return fetch(url, opts).then(r => r.json());
  }
};

const $ = id => document.getElementById(id);
const show = (m, ok=true) => { $('msg').textContent = m; $('msg').style.color = ok ? 'green' : 'red'; };

function populateSelect(id, items, includeEmpty=true) {
  const sel = $(id); sel.innerHTML=''; if(includeEmpty){ sel.appendChild(new Option('--','')); }
  items.forEach(v=> sel.appendChild(new Option(v,v)));
}
populateSelect('complaints_source', ["الخط الساخن","الايميل","شخصياً بالمكتب","الادارة"]);
populateSelect('sample_status', ["موجودة","غير موجودة","تم ارسال صورها بالالهاتف","لا يوجد عينة للشكوى"]);
populateSelect('complainant_contact_action', ["تم مقابلة الشاكى واستلام العينة","تم الاتصال بالشاكى"]);
populateSelect('complaint_category', ["النظافة العامة","طرق اعداد وحفظ","الغش التجارى"]);
populateSelect('complaint_status', ['','حفظ','قيد الاجراء','تم الإغلاق']);

// Search establishments
$('btnSearch').addEventListener('click', async ()=>{
  const license = $('license_no').value.trim();
  if(!license){ $('estList').textContent='أدخل رقم الترخيص'; return; }
  $('estList').textContent = 'جاري البحث...';
  try{
    const res = await api({ action:'establishments', license_no: license });
    if(!res.success){ $('estList').textContent = res.message || 'خطأ'; return; }
    $('estList').innerHTML = '';
    if(!res.data.length){ $('estList').textContent = 'لا توجد منشآت'; return; }
    res.data.forEach(e=>{
      const b = document.createElement('button'); b.type='button';
      b.textContent = (e.facility_name || e.unique_id) + ' — ' + e.unique_id;
      b.onclick = ()=> {
        $('establishment_unique_id').value = e.unique_id;
        $('license_no_display').value = e.license_no || '';
        $('facility_name').value = e.facility_name || '';
        Array.from($('estList').querySelectorAll('button')).forEach(x=>x.disabled=false);
        b.disabled = true;
      };
      $('estList').appendChild(b);
    });
  } catch(e){ console.error(e); $('estList').textContent='خطأ في الاتصال'; }
});

// load list
async function loadList(){
  $('list').textContent = 'جاري التحميل...';
  try {
    const params = { action:'list' };
    const res = await api(params, 'GET');
    $('raw').textContent = JSON.stringify(res, null, 2);
    if(!res.success){ $('list').textContent = res.message || 'خطأ'; return; }
    if(!res.data.length){ $('list').textContent = 'لا توجد شكاوى'; return; }
    let html = '<table><thead><tr><th>#</th><th>المنشأة</th><th>استلام</th><th>المفتش</th><th>السوبرفايزر</th><th>المدير</th><th>موضوع</th><th>إجراءات</th></tr></thead><tbody>';
    res.data.forEach((r,i)=>{
      const inspector = r.created_by_empid || '';
      const sup = r.supervisor_empid || '';
      const mgr = r.manager_empid || '';
      html += `<tr>
        <td>${i+1}</td>
        <td>${r.establishment_unique_id||''}</td>
        <td>${r.received_datetime||''}</td>
        <td>${inspector}</td>
        <td>${sup}</td>
        <td>${mgr}</td>
        <td>${(r.complaint_subject||'').slice(0,80)}</td>
        <td>
          <button class="open" data-id="${r.id}">فتح</button>
        </td>
      </tr>`;
    });
    html += '</tbody></table>';
    $('list').innerHTML = html;
    document.querySelectorAll('.open').forEach(b=> b.addEventListener('click', ()=> openComplaint(b.dataset.id)));
  } catch(e){ console.error(e); $('list').textContent = 'خطأ تحميل'; }
}

async function openComplaint(id){
  try {
    const res = await api({ action:'get', id }, 'GET');
    if(!res.success){ show('فشل جلب الشكوى: ' + (res.message||''), false); return; }
    const c = res.data;
    // populate all fields
    const set = (id, v)=>{
      const el = document.getElementById(id); if(!el) return;
      if(el.type === 'datetime-local') el.value = v ? v.replace(' ', 'T').slice(0,16) : '';
      else el.value = v ?? '';
    };
    Object.keys(c).forEach(k => {
      // map DB column names to form ids (most same)
      set(k, c[k]);
    });
    // some aliases
    set('license_no_display', c.license_no ?? '');
    set('facility_name', c.facility_name ?? '');
    show('تم تحميل الشكوى');
  } catch(e){ console.error(e); show('خطأ فتح الشكوى', false); }
}

$('saveBtn').addEventListener('click', async ()=>{
  try {
    const data = { action:'create' };
    // collect many fields
    const fields = ['establishment_unique_id','received_datetime','complaints_source','hotline_complaint_number','complainant_name','complainant_phone','complainant_statement','inspector_followup','sample_status','complainant_contact_action','inspector_received_datetime','complaint_subject','site_manager_statement','supervisor_empid','manager_empid','supervisor_comment','complaint_category','complaint_status','response_speed','section_actions','food_poisoning_suspect','division_head_comment','taken_actions','followup_datetime','closed_datetime','attachment_url'];
    fields.forEach(f => {
      const el = document.getElementById(f);
      if(!el) return;
      let v = el.value || '';
      if(el.type === 'datetime-local' && v) { /* keep as-is; server expects same format */ }
      data[f] = v;
    });
    const res = await api(data, 'POST');
    if(!res.success){ show('فشل الإنشاء: ' + (res.message||''), false); return; }
    $('id').value = res.id;
    show('تم الإنشاء ID=' + res.id);
    loadList();
  } catch(e){ console.error(e); show('خطأ الحفظ', false); }
});

$('updateBtn').addEventListener('click', async ()=>{
  try {
    const id = $('id').value;
    if(!id) return show('افتح الشكوى أولاً', false);
    const data = { action:'update', id };
    const fields = ['establishment_unique_id','received_datetime','complaints_source','hotline_complaint_number','complainant_name','complainant_phone','complainant_statement','inspector_followup','sample_status','complainant_contact_action','inspector_received_datetime','complaint_subject','site_manager_statement','supervisor_empid','manager_empid','supervisor_comment','complaint_category','complaint_status','response_speed','section_actions','food_poisoning_suspect','division_head_comment','taken_actions','followup_datetime','closed_datetime','attachment_url'];
    fields.forEach(f => { const el = document.getElementById(f); if(!el) return; data[f] = el.value || ''; });
    const res = await api(data, 'POST');
    if(!res.success){ show('فشل التحديث: ' + (res.message||''), false); return; }
    show('تم التحديث');
    loadList();
  } catch(e){ console.error(e); show('خطأ التحديث', false); }
});

$('deleteBtn').addEventListener('click', async ()=>{
  try {
    const id = $('id').value;
    if(!id) return show('افتح الشكوى أولاً', false);
    if(!confirm('حذف الشكوى؟')) return;
    const res = await api({ action:'delete', id }, 'POST');
    if(!res.success){ show('فشل الحذف: ' + (res.message||''), false); return; }
    show('تم الحذف');
    document.getElementById('form').reset();
    loadList();
  } catch(e){ console.error(e); show('خطأ الحذف', false); }
});

// sign supervisor/manager via session user_data.php
$('btnSignSupervisor').addEventListener('click', async ()=>{
  try {
    const id = $('id').value; if(!id) return show('افتح الشكوى أولاً', false);
    // server expects supervisor_empid and followup_datetime in update call
    const resUser = await api({ action:'user' }, 'GET'); // user action route isn't implemented; instead call user_data.php directly
    // We will call user_data.php directly
    const ud = await fetch('../user_data.php', { credentials:'same-origin' }).then(r=>r.json());
    if(!ud.success) return show('لا يوجد جلسة', false);
    const empid = ud.user_data?.EmpID;
    const followup = new Date().toISOString().slice(0,19).replace('T',' ');
    const res = await api({ action:'update', id, supervisor_empid: empid, supervisor_comment: $('supervisor_comment').value || '', followup_datetime: followup }, 'POST');
    if(!res.success) return show('فشل توقيع السوبرفايزر: ' + (res.message||''), false);
    $('supervisor_empid').value = empid;
    $('followup_datetime').value = followup.replace(' ', 'T').slice(0,16);
    show('تم توقيع السوبرفايزر');
    loadList();
  } catch(e){ console.error(e); show('خطأ توقيع السوبرفايزر', false); }
});

$('btnSignManager').addEventListener('click', async ()=>{
  try {
    const id = $('id').value; if(!id) return show('افتح الشكوى أولاً', false);
    const ud = await fetch('../user_data.php', { credentials:'same-origin' }).then(r=>r.json());
    if(!ud.success) return show('لا يوجد جلسة', false);
    const empid = ud.user_data?.EmpID;
    const closed = new Date().toISOString().slice(0,19).replace('T',' ');
    const res = await api({ action:'update', id, manager_empid: empid, division_head_comment: $('division_head_comment').value || '', complaint_status: $('complaint_status').value || '', closed_datetime: closed }, 'POST');
    if(!res.success) return show('فشل توقيع المدير: ' + (res.message||''), false);
    $('manager_empid').value = empid;
    $('closed_datetime').value = closed.replace(' ', 'T').slice(0,16);
    show('تم توقيع المدير وإغلاق الشكوى');
    loadList();
  } catch(e){ console.error(e); show('خطأ توقيع المدير', false); }
});

// initial load
$('btnReload').addEventListener('click', loadList);
$('btnApply').addEventListener('click', loadList);
$('btnClear').addEventListener('click', ()=> { $('searchAll').value=''; loadList(); });

// load at start
loadList();
</script>
</body>
</html>