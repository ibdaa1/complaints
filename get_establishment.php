<?php
// get_establishment.php
// Returns establishment details from the `establishments` table.
// Robust: reads actual columns from the DB (SHOW COLUMNS) and only selects existing columns.
// - If ?unique_id=... is provided -> returns single object in "data".
// - If ?license_no=... is provided:
//     - if facility_name is provided -> try to return the best match (single object).
//     - otherwise -> returns an array of matches in "data".
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

$unique_id     = isset($_GET['unique_id']) ? trim($_GET['unique_id']) : '';
$license_no    = isset($_GET['license_no']) ? trim($_GET['license_no']) : '';
$facility_name = isset($_GET['facility_name']) ? trim($_GET['facility_name']) : '';

try {
    // get actual columns of the establishments table to avoid Unknown column errors
    $colsRes = $conn->query("SHOW COLUMNS FROM `establishments`");
    if (!$colsRes) json_err('DB error: ' . $conn->error, 500);
    $availableCols = [];
    while ($row = $colsRes->fetch_assoc()) {
        $availableCols[] = $row['Field'];
    }

    // candidate columns we want to return (based on your schema)
    $wanted = [
        'ID','license_no','LicenseIssuing','ltype','sub_no','unique_id','facility_name','brand_name',
        'area','sub_area','description','Building','activity_type','detailed_activities','facility_status',
        'unit','Sub_UNIT','shfhsp','hazard_class','site_coordinates','Sector','Sub_Sector',
        'lstart_date','lend_date','user','area_id','phone_number','email','front_image_url',
        'entry_permit_no','created_at','updated_at'
    ];

    // only keep columns that actually exist in table
    $selectCols = array_values(array_intersect($wanted, $availableCols));
    if (empty($selectCols)) {
        // fallback to selecting all columns if intersection unexpectedly empty
        $selectClause = '*';
    } else {
        // escape column names with backticks
        $selectClause = implode(', ', array_map(function($c){ return "`$c`"; }, $selectCols));
    }

    // If unique_id provided -> return single object
    if ($unique_id !== '') {
        $sql = "SELECT $selectClause FROM `establishments` WHERE `unique_id` = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        $stmt->bind_param('s', $unique_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        if (!$row) json_err('Establishment not found for unique_id: ' . $unique_id, 404);
        json_ok(['data' => $row]);
    }

    // If license_no provided
    if ($license_no !== '') {
        // If facility_name provided -> try to find closest match (single)
        if ($facility_name !== '') {
            $sql = "SELECT $selectClause FROM `establishments` WHERE `license_no` = ? AND `facility_name` LIKE ? ORDER BY `facility_name` ASC LIMIT 1";
            $likeName = '%' . $facility_name . '%';
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ss', $license_no, $likeName);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                if ($row) json_ok(['data' => $row]);
                // otherwise fallthrough to return all by license_no
            }
            // if prepare failed, we'll continue to the general query
        }

        // return all matches for license_no
        $sql = "SELECT $selectClause FROM `establishments` WHERE `license_no` = ? ORDER BY `facility_name` ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) json_err('DB prepare failed: ' . $conn->error, 500);
        $stmt->bind_param('s', $license_no);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) json_err('No establishment found for license_no: ' . $license_no, 404);
        json_ok(['data' => $rows]);
    }

    // nothing provided
    json_err('Provide unique_id or license_no as query parameter', 400);

} catch (Throwable $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}
?>