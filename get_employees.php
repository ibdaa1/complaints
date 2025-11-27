<?php
// /get_employees.php - جلب قائمة الموظفين (EmpID, EmpName)

// 1. إعدادات PHP وكشف الأخطاء (للتصحيح)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 2. تضمين ملف الاتصال بقاعدة البيانات ($conn)
// يجب أن يكون db.php في نفس المجلد ويحتوي على تعريف $conn
require_once '../db.php';

// 3. (اختياري) بدء الجلسة إذا كنت تحتاجها، ولكن يجب ألا تتداخل مع الإخراج
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 4. التأكد من وجود اتصال قاعدة البيانات
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
}

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'data' => []];

try {
    // الاستعلام لجلب رقم الموظف واسمه فقط (جميع السجلات)
    $result = $conn->query("SELECT EmpID, EmpName FROM Users ORDER BY EmpName ASC"); 

    if ($result) {
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        $response['success'] = true;
        $response['data'] = $employees; // ⬅️ الإرجاع الآن هو قائمة الموظفين
    } else {
        throw new Exception("SQL Query Error: " . $conn->error);
    }
} catch (Exception $e) {
    error_log("Get Employees Failed: " . $e->getMessage());
    $response['message'] = "Database error fetching employees: " . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>