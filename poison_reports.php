<?php
// public_html/shjfcs/complaints/poison_reports.php
// Backend API for poison_reports, poison_contacts, poison_meals
// - Requires: ../db.php which defines $conn (mysqli)
// - Save as UTF-8 without BOM
// - Upload folder: uploads/poison_reports/ (relative to this file)

// Start session and DB
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>false,'message'=>'Database connection not found'], JSON_UNESCAPED_UNICODE);
    exit;
}
$conn->set_charset('utf8mb4');

// Helper: JSON response
function respond($ok, $msg = '', $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => $ok, 'message' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: normalize incoming value (empty string -> null if requested)
function val_or_null($v) {
    if (!isset($v)) return null;
    // Keep empty string as empty string (some fields may want empty string)
    // But treat strings that are exactly '' as NULL if desired - currently keep as NULL if empty string
    if ($v === '') return null;
    return $v;
}

// Get current user EmpID from session (optional)
$empID = $_SESSION['user']['EmpID'] ?? null;

// Ensure uploads directory exists
$uploadDir = __DIR__ . '/uploads/poison_reports/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// Router
$action = $_REQUEST['action'] ?? '';

// Ensure temp uploads directory exists
$tempUploadDir = __DIR__ . '/uploads/poison_reports/temp/';
if (!is_dir($tempUploadDir)) {
    @mkdir($tempUploadDir, 0755, true);
}

// -------------------------
// Allowed fields for poison_reports (must match DB columns)
$report_allowed = [
    'establishment_unique_id','source_type','source_name','source_area','source_activity','source_description',
    'report_datetime','inspector_received_datetime','report_source','infection_officer','infection_officer_phone',
    'hospital_name','admission_datetime','food_source','facility_unique_id','eating_datetime',
    'samples_entry_datetime','samples_result_datetime','followup_datetime','closed_datetime',
    'consumed_on_site','last_pest_control','suspected_food','total_consumers','total_symptomatic',
    'patient_samples_taken','patient_samples_results','establishment_samples_taken',
    'establishment_samples_results','time_between_food_and_symptoms','symptoms','establishment_actions',
    'production_volume','initial_diagnosis','final_diagnosis','final_result',
    'investigation_recommendations','investigation_team_members',
    'supervisor_empid','section_head_empid','division_head_empid','section_head_datetime',
    'form_number','attachments','notes'
];
// -------------------------

try {

    switch($action) {

        // ----------------- SAVE (CREATE or UPDATE) report -----------------
        case 'save_report':
            if (!$empID) return respond(false,'Unauthorized: session missing EmpID');

            // collect posted fields
            $post = $_POST;
            $id = isset($post['id']) && intval($post['id'])>0 ? intval($post['id']) : 0;

            // Build data array only for allowed fields
            $data = [];
            foreach ($report_allowed as $f) {
                if (array_key_exists($f, $post)) {
                    $v = $post[$f];
                    // normalize empty strings to NULL except attachments (we handle attachments separately)
                    if ($f === 'attachments') {
                        $data[$f] = $v === '' ? null : $v;
                    } else {
                        $data[$f] = ($v === '' ? null : $v);
                    }
                }
            }

            // Handle pending attachments (uploaded before save)
            $pendingAttachments = [];
            if (isset($post['pending_attachments']) && $post['pending_attachments'] !== '') {
                $pendingAttachments = json_decode($post['pending_attachments'], true);
                if (!is_array($pendingAttachments)) $pendingAttachments = [];
            }

            // set audit fields
            if ($id > 0) {
                $data['updated_by_empid'] = $empID;
            } else {
                $data['created_by_empid'] = $empID;
                $data['updated_by_empid'] = $empID;
            }

            // Validate at least something to insert/update
            if (empty($data)) {
                return respond(false, 'No valid fields provided for report.');
            }

            if ($id > 0) {
                // UPDATE
                $sets = [];
                foreach ($data as $col => $val) {
                    if (is_null($val)) $sets[] = "`$col` = NULL";
                    else $sets[] = "`$col` = '".$conn->real_escape_string($val)."'";
                }
                $sql = "UPDATE `poison_reports` SET ".implode(', ', $sets)." WHERE id = ".intval($id)." LIMIT 1";
                if ($conn->query($sql)) {
                    // Move pending attachments from temp
                    if (!empty($pendingAttachments)) {
                        $res = $conn->query("SELECT attachments FROM poison_reports WHERE id = ".intval($id)." LIMIT 1");
                        $existingAtt = [];
                        if ($res && $row = $res->fetch_assoc()) {
                            $existingAtt = $row['attachments'] ? json_decode($row['attachments'], true) : [];
                            if (!is_array($existingAtt)) $existingAtt = [];
                        }
                        foreach ($pendingAttachments as $tempFile) {
                            $tempPath = $tempUploadDir . basename($tempFile);
                            if (file_exists($tempPath)) {
                                $newName = 'pr_'.$id.'_'.time().'_'.basename($tempFile);
                                $newPath = $uploadDir . $newName;
                                if (rename($tempPath, $newPath)) {
                                    $existingAtt[] = $newName;
                                }
                            }
                        }
                        $att_json = $conn->real_escape_string(json_encode(array_values($existingAtt), JSON_UNESCAPED_UNICODE));
                        $conn->query("UPDATE poison_reports SET attachments = '$att_json' WHERE id = ".intval($id));
                    }
                    respond(true, 'Report updated', $id);
                } else {
                    respond(false, 'Update failed: '.$conn->error);
                }
            } else {
                // INSERT
                $cols = array_keys($data);
                $cols_escaped = array_map(function($c){ return "`$c`"; }, $cols);
                $vals = [];
                foreach ($data as $val) {
                    if (is_null($val)) $vals[] = "NULL";
                    else $vals[] = "'".$conn->real_escape_string($val)."'";
                }
                // Safety: ensure we have at least one column to insert
                if (empty($cols_escaped)) return respond(false,'Nothing to insert');
                $sql = "INSERT INTO `poison_reports` (".implode(', ', $cols_escaped).") VALUES (".implode(', ', $vals).")";
                if ($conn->query($sql)) {
                    $newId = $conn->insert_id;
                    
                    // Move pending attachments from temp
                    if (!empty($pendingAttachments)) {
                        $newAtt = [];
                        foreach ($pendingAttachments as $tempFile) {
                            $tempPath = $tempUploadDir . basename($tempFile);
                            if (file_exists($tempPath)) {
                                $newName = 'pr_'.$newId.'_'.time().'_'.basename($tempFile);
                                $newPath = $uploadDir . $newName;
                                if (rename($tempPath, $newPath)) {
                                    $newAtt[] = $newName;
                                }
                            }
                        }
                        if (!empty($newAtt)) {
                            $att_json = $conn->real_escape_string(json_encode($newAtt, JSON_UNESCAPED_UNICODE));
                            $conn->query("UPDATE poison_reports SET attachments = '$att_json' WHERE id = ".intval($newId));
                        }
                    }
                    
                    respond(true, 'Report created', $newId);
                } else {
                    respond(false, 'Insert failed: '.$conn->error);
                }
            }
            break;

        // ----------------- DELETE report -----------------
        case 'delete_report':
            if (!$empID) return respond(false,'Unauthorized');
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id <= 0) return respond(false,'Invalid ID');
            // delete attachments files if any
            $res = $conn->query("SELECT attachments FROM poison_reports WHERE id = ".intval($id)." LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $attachments = $row['attachments'] ? json_decode($row['attachments'], true) : [];
                if (is_array($attachments)) {
                    foreach ($attachments as $f) {
                        $p = $uploadDir . $f;
                        if (file_exists($p)) @unlink($p);
                    }
                }
            }
            $sql = "DELETE FROM poison_reports WHERE id = ".intval($id);
            if ($conn->query($sql)) respond(true,'Report deleted');
            else respond(false,'Delete failed: '.$conn->error);
            break;

        // ----------------- SEARCH reports -----------------
        case 'search_reports':
            // Support q (text), facility_unique_id, date_from, date_to, limit
            $q = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
            $facility = isset($_GET['facility_unique_id']) ? $conn->real_escape_string($_GET['facility_unique_id']) : '';
            $date_from = isset($_GET['date_from']) ? $conn->real_escape_string($_GET['date_from']) : '';
            $date_to = isset($_GET['date_to']) ? $conn->real_escape_string($_GET['date_to']) : '';
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 200;

            $where = [];
            if ($q !== '') {
                $q_esc = $q;
                $w = "(source_name LIKE '%$q_esc%' OR food_source LIKE '%$q_esc%' OR hospital_name LIKE '%$q_esc%' OR suspected_food LIKE '%$q_esc%')";
                $where[] = $w;
            }
            if ($facility !== '') {
                $where[] = " (facility_unique_id = '$facility' OR establishment_unique_id = '$facility') ";
            }
            if ($date_from !== '') {
                $where[] = " report_datetime >= '$date_from' ";
            }
            if ($date_to !== '') {
                $where[] = " report_datetime <= '$date_to' ";
            }
            $sql = "SELECT * FROM poison_reports";
            if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY report_datetime DESC LIMIT ".max(1,$limit);

            $res = $conn->query($sql);
            if (!$res) respond(false,'Search query failed: '.$conn->error);
            $out = [];
            while ($r = $res->fetch_assoc()) $out[] = $r;
            respond(true, '', $out);
            break;

        // ----------------- GET SINGLE REPORT -----------------
        case 'get_report':
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            if ($id <= 0) return respond(false,'Invalid report ID');
            $res = $conn->query("SELECT * FROM poison_reports WHERE id = ".intval($id)." LIMIT 1");
            if (!$res) return respond(false,'Query failed: '.$conn->error);
            $row = $res->fetch_assoc();
            if (!$row) return respond(false,'Report not found');
            respond(true, '', $row);
            break;

        // ----------------- CONTACTS CRUD -----------------
        case 'list_contacts':
            $report_id = isset($_GET['poison_report_id']) ? intval($_GET['poison_report_id']) : 0;
            if ($report_id <= 0) return respond(false,'Invalid report ID');
            $res = $conn->query("SELECT * FROM poison_contacts WHERE poison_report_id = ".intval($report_id)." ORDER BY id ASC");
            if (!$res) return respond(false,'Query failed: '.$conn->error);
            $rows = [];
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            respond(true,'',$rows);
            break;

        case 'save_contact':
            if (!$empID) return respond(false,'Unauthorized');
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $report_id = isset($_POST['poison_report_id']) ? intval($_POST['poison_report_id']) : 0;
            if ($report_id <= 0) return respond(false,'Invalid report ID');

            $fields = ['contact_name','contact_age','contact_gender','contact_phone','contact_info_source','symptoms'];
            $data = ['poison_report_id' => $report_id];
            foreach ($fields as $f) {
                if (isset($_POST[$f]) && $_POST[$f] !== '') $data[$f] = $conn->real_escape_string($_POST[$f]);
                else $data[$f] = null;
            }

            if ($id > 0) {
                $sets = [];
                foreach ($data as $col => $val) {
                    $sets[] = "`$col` = " . (is_null($val) ? "NULL" : "'$val'");
                }
                $sql = "UPDATE poison_contacts SET ".implode(', ', $sets)." WHERE id = ".intval($id)." LIMIT 1";
                if ($conn->query($sql)) respond(true,'Contact updated', $id);
                else respond(false,'Update failed: '.$conn->error);
            } else {
                $cols = array_keys($data);
                $cols_esc = array_map(fn($c) => "`$c`", $cols);
                $vals = array_map(function($v) { return is_null($v) ? "NULL" : "'$v'"; }, array_values($data));
                $sql = "INSERT INTO poison_contacts (".implode(', ', $cols_esc).") VALUES (".implode(', ', $vals).")";
                if ($conn->query($sql)) respond(true,'Contact created',$conn->insert_id);
                else respond(false,'Insert failed: '.$conn->error);
            }
            break;

        case 'delete_contact':
            if (!$empID) return respond(false,'Unauthorized');
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id <= 0) return respond(false,'Invalid ID');
            if ($conn->query("DELETE FROM poison_contacts WHERE id = ".intval($id))) respond(true,'Contact deleted');
            else respond(false,'Delete failed: '.$conn->error);
            break;

        // ----------------- MEALS CRUD -----------------
        case 'list_meals':
            $report_id = isset($_GET['poison_report_id']) ? intval($_GET['poison_report_id']) : 0;
            if ($report_id <= 0) return respond(false,'Invalid report ID');
            $res = $conn->query("SELECT * FROM poison_meals WHERE poison_report_id = ".intval($report_id)." ORDER BY day_number ASC, meal_type ASC");
            if (!$res) return respond(false,'Query failed: '.$conn->error);
            $rows = [];
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            respond(true,'',$rows);
            break;

        case 'save_meal':
            if (!$empID) return respond(false,'Unauthorized');
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $report_id = isset($_POST['poison_report_id']) ? intval($_POST['poison_report_id']) : 0;
            if ($report_id <= 0) return respond(false,'Invalid report ID');
            $fields = ['day_number','meal_type','meal_name','meal_details'];
            $data = ['poison_report_id' => $report_id];
            foreach ($fields as $f) $data[$f] = isset($_POST[$f]) && $_POST[$f] !== '' ? $conn->real_escape_string($_POST[$f]) : null;

            if ($id > 0) {
                $sets = [];
                foreach ($data as $col => $val) $sets[] = "`$col` = " . (is_null($val) ? "NULL" : "'$val'");
                $sql = "UPDATE poison_meals SET ".implode(', ', $sets)." WHERE id = ".intval($id)." LIMIT 1";
                if ($conn->query($sql)) respond(true,'Meal updated',$id);
                else respond(false,'Update failed: '.$conn->error);
            } else {
                $cols = array_keys($data);
                $cols_esc = array_map(fn($c)=>"`$c`", $cols);
                $vals = array_map(fn($v)=>is_null($v) ? "NULL" : "'$v'", array_values($data));
                $sql = "INSERT INTO poison_meals (".implode(', ', $cols_esc).") VALUES (".implode(', ', $vals).")";
                if ($conn->query($sql)) respond(true,'Meal created',$conn->insert_id);
                else respond(false,'Insert failed: '.$conn->error);
            }
            break;

        case 'delete_meal':
            if (!$empID) return respond(false,'Unauthorized');
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id <= 0) return respond(false,'Invalid ID');
            if ($conn->query("DELETE FROM poison_meals WHERE id = ".intval($id))) respond(true,'Meal deleted');
            else respond(false,'Delete failed: '.$conn->error);
            break;

        // ----------------- ATTACHMENTS UPLOAD -----------------
        case 'upload_attachment':
            if (!$empID) return respond(false,'Unauthorized');
            $report_id = isset($_POST['poison_report_id']) ? intval($_POST['poison_report_id']) : 0;
            if ($report_id <= 0) return respond(false,'Invalid report ID');
            if (!isset($_FILES['file'])) return respond(false,'No file uploaded');

            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) return respond(false,'Upload error code: '.$file['error']);

            // sanitize original name and create unique name (no dots in filename to prevent double extensions)
            $orig = basename($file['name']);
            $ext = pathinfo($orig, PATHINFO_EXTENSION);
            $safeName = preg_replace('/[^A-Za-z0-9_\-]/u','_', pathinfo($orig, PATHINFO_FILENAME));
            $newName = 'pr_'.$report_id.'_'.time().'_'.$safeName.($ext?('.'.$ext):'');
            $dest = $uploadDir . $newName;

            if (!move_uploaded_file($file['tmp_name'], $dest)) return respond(false,'Failed to move uploaded file');

            // update attachments JSON array
            $res = $conn->query("SELECT attachments FROM poison_reports WHERE id = ".intval($report_id)." LIMIT 1");
            $attachments = [];
            if ($res && $row = $res->fetch_assoc()) {
                $attachments = $row['attachments'] ? json_decode($row['attachments'], true) : [];
                if (!is_array($attachments)) $attachments = [];
            }
            $attachments[] = $newName;
            $att_json = $conn->real_escape_string(json_encode(array_values($attachments), JSON_UNESCAPED_UNICODE));
            $conn->query("UPDATE poison_reports SET attachments = '$att_json' WHERE id = ".intval($report_id));

            respond(true,'File uploaded', ['filename'=>$newName, 'path'=>'uploads/poison_reports/'.$newName]);
            break;

        case 'delete_attachment':
            if (!$empID) return respond(false,'Unauthorized');
            $report_id = isset($_POST['poison_report_id']) ? intval($_POST['poison_report_id']) : 0;
            $filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';
            if ($report_id <= 0 || $filename === '') return respond(false,'Invalid request');
            // Validate filename - basename already removes directory components
            $filename = basename($filename);

            $res = $conn->query("SELECT attachments FROM poison_reports WHERE id = ".intval($report_id)." LIMIT 1");
            if (!$res) return respond(false,'DB error: '.$conn->error);
            $attachments = [];
            if ($row = $res->fetch_assoc()) {
                $attachments = $row['attachments'] ? json_decode($row['attachments'], true) : [];
                if (!is_array($attachments)) $attachments = [];
            }
            $idx = array_search($filename, $attachments);
            if ($idx === false) return respond(false,'Attachment not found');
            unset($attachments[$idx]);
            $attachments = array_values($attachments);
            $att_json = $conn->real_escape_string(json_encode($attachments, JSON_UNESCAPED_UNICODE));
            if (!$conn->query("UPDATE poison_reports SET attachments = '$att_json' WHERE id = ".intval($report_id))) {
                return respond(false,'Failed to update attachments: '.$conn->error);
            }
            // delete file
            $filePath = $uploadDir . $filename;
            if (file_exists($filePath)) @unlink($filePath);
            respond(true,'Attachment deleted');
            break;

        // ----------------- TEMP UPLOAD (before saving report) -----------------
        case 'upload_temp':
            if (!$empID) return respond(false,'Unauthorized');
            if (!isset($_FILES['file'])) return respond(false,'No file uploaded');

            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) return respond(false,'Upload error code: '.$file['error']);

            // sanitize original name and create unique name
            $orig = basename($file['name']);
            $ext = pathinfo($orig, PATHINFO_EXTENSION);
            $safeName = preg_replace('/[^A-Za-z0-9_\-]/u','_', pathinfo($orig, PATHINFO_FILENAME));
            $newName = 'temp_'.time().'_'.$safeName.($ext?('.'.$ext):'');
            $dest = $tempUploadDir . $newName;

            if (!move_uploaded_file($file['tmp_name'], $dest)) return respond(false,'Failed to move uploaded file');

            respond(true,'Temp file uploaded', ['filename'=>$newName, 'path'=>'uploads/poison_reports/temp/'.$newName]);
            break;

        case 'delete_temp':
            if (!$empID) return respond(false,'Unauthorized');
            $filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';
            if ($filename === '') return respond(false,'Invalid request');
            // Validate filename - basename already removes directory components
            $filename = basename($filename);
            
            $filePath = $tempUploadDir . $filename;
            if (file_exists($filePath)) @unlink($filePath);
            respond(true,'Temp file deleted');
            break;

        default:
            respond(false,'Unknown action: '.$action);
    }

} catch (Throwable $e) {
    respond(false,'Server error: '.$e->getMessage());
}
