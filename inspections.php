<?php
// inspections.php
// Endpoint for inspection-related queries.
// Supports:
//   - action=last_visit&unique_id=...&type=... (optional type filter)
//   - action=actions&inspection_id=...
//
// Requires ../db.php to define $conn (mysqli).
// Save as UTF-8 without BOM.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function json_ok($payload = []) {
    echo json_encode(array_merge(['success' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    json_err('DB connection missing', 500);
}
$conn->set_charset('utf8mb4');

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// action=last_visit - returns the most recent inspection for a facility
if ($action === 'last_visit') {
    $unique_id = isset($_GET['unique_id']) ? trim($_GET['unique_id']) : '';
    if ($unique_id === '') {
        json_err('unique_id parameter is required', 400);
    }

    $type = isset($_GET['type']) ? trim($_GET['type']) : '';

    // Build query to get the latest inspection for the facility
    $sql = "SELECT inspection_id, facility_unique_id, inspection_date, inspection_type, campaign_name,
                   inspector_user_id, approval_status, notes
            FROM tbl_inspections
            WHERE facility_unique_id = ?";
    $params = [$unique_id];
    $types = 's';

    // Optional type filter (supports both شكوى and شكوي spellings)
    if ($type !== '') {
        $sql .= " AND (inspection_type = ? OR inspection_type = ?)";
        // Handle both common spellings
        $typeAlt = ($type === 'شكوى') ? 'شكوي' : (($type === 'شكوي') ? 'شكوى' : $type);
        $params[] = $type;
        $params[] = $typeAlt;
        $types .= 'ss';
    }

    $sql .= " ORDER BY inspection_date DESC, inspection_id DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        json_err('DB prepare failed: ' . $conn->error, 500);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_ok(['data' => null, 'message' => 'No inspection found']);
    }

    json_ok(['data' => $row]);
}

// action=actions - returns actions for a specific inspection
if ($action === 'actions') {
    $inspection_id = isset($_GET['inspection_id']) ? trim($_GET['inspection_id']) : '';
    if ($inspection_id === '') {
        json_err('inspection_id parameter is required', 400);
    }

    $sql = "SELECT id, inspection_id, action_name, action_number, action_date, notes
            FROM tbl_inspection_actions
            WHERE inspection_id = ?
            ORDER BY action_date DESC, id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        json_err('DB prepare failed: ' . $conn->error, 500);
    }

    $stmt->bind_param('s', $inspection_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json_ok(['data' => $rows]);
}

// Unknown or missing action
json_err('Invalid or missing action parameter. Supported: last_visit, actions', 400);
?>
