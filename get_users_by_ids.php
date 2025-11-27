<?php
/**
 * get_users_by_ids.php
 *
 * Usage:
 *   get_users_by_ids.php?ids=32932,28332
 *
 * Returns JSON:
 * {
 *   "success": true,
 *   "data": {
 *     "32932": "اسم الموظف",
 *     "28332": "اسم آخر"
 *   }
 * }
 *
 * Requirements:
 * - db.php must exist in same folder (or one of parent paths) and define $conn (MySQLi).
 */

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

try {
    // Try common locations for db.php
    $candidates = [
        __DIR__ . '/db.php',
        __DIR__ . '/../db.php',
        __DIR__ . '/../../db.php'
    ];
    $included = false;
    foreach ($candidates as $p) {
        if (file_exists($p)) {
            require_once $p;
            $included = true;
            break;
        }
    }
    if (!$included) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db.php not found in expected paths.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!isset($conn) || !$conn) throw new Exception('Database connection ($conn) not available.');

    $idsParam = $_GET['ids'] ?? '';
    if (trim($idsParam) === '') {
        echo json_encode(['success' => true, 'data' => new stdClass()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Parse and sanitize ids
    $parts = array_filter(array_map('trim', explode(',', $idsParam)), fn($v) => $v !== '');
    $ids = array_values(array_unique(array_map(function($v){
        // allow numeric strings only
        if (is_numeric($v)) return (int)$v;
        return null;
    }, $parts)));
    $ids = array_filter($ids, fn($v) => $v !== null && $v !== 0);

    if (empty($ids)) {
        echo json_encode(['success' => true, 'data' => new stdClass()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // limit to reasonable count (avoid extremely large IN lists)
    $maxAllowed = 500;
    if (count($ids) > $maxAllowed) {
        $ids = array_slice($ids, 0, $maxAllowed);
    }

    // Build query with placeholders
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT EmpID, EmpName FROM Users WHERE EmpID IN ($placeholders)";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) throw new Exception('Prepare failed: ' . $conn->error);

    // bind params dynamically
    $bindParams = [];
    $bindParams[] = & $types;
    // need references
    foreach ($ids as $k => $v) {
        $bindParams[] = & $ids[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);

    $stmt->execute();
    $res = $stmt->get_result();

    $map = [];
    while ($row = $res->fetch_assoc()) {
        $map[(string)$row['EmpID']] = $row['EmpName'];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $map], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
?>