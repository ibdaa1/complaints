<?php
// /user_data.php - نقطة نهاية لجلب بيانات المستخدم عبر AJAX

// 1. إعدادات PHP الأساسية
ini_set('display_errors', 0);
error_reporting(0);

// 2. بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. إعداد استجابة JSON
header('Content-Type: application/json; charset=utf-8');

// 4. دوال التحقق من الصلاحيات
function canAdd() {
    return isset($_SESSION['user']['CanAdd']) && (int)$_SESSION['user']['CanAdd'] === 1;
}
function canEdit() {
    return isset($_SESSION['user']['CanEdit']) && (int)$_SESSION['user']['CanEdit'] === 1;
}
function canDelete() {
    return isset($_SESSION['user']['CanDelete']) && (int)$_SESSION['user']['CanDelete'] === 1;
}
function canSendWhatsApp() {
    return isset($_SESSION['user']['CanSendWhatsApp']) && (int)$_SESSION['user']['CanSendWhatsApp'] === 1;
}
function isLicenseManager() {
    return isset($_SESSION['user']['IsLicenseManager']) && (int)$_SESSION['user']['IsLicenseManager'] === 1;
}
function isLicenseInspector() {
    return isset($_SESSION['user']['IsLicenseInspector']) && (int)$_SESSION['user']['IsLicenseInspector'] === 1;
}
function isAdmin() {
    return isset($_SESSION['user']['IsAdmin']) && (int)$_SESSION['user']['IsAdmin'] === 1;
}
function isSuperAdmin() {
    return isset($_SESSION['user']['IsSuperAdmin']) && (int)$_SESSION['user']['IsSuperAdmin'] === 1;
}
function hasSignature() {
    return isset($_SESSION['user']['HasSignature']) && (int)$_SESSION['user']['HasSignature'] === 1;
}

// 5. بناء الاستجابة
$response = [];

if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $response = [
        'success' => true,
        'user_data' => $_SESSION['user'],
        'permissions' => [
            'canAdd'            => canAdd(),
            'canEdit'           => canEdit(),
            'canDelete'         => canDelete(),
            'canSendWhatsApp'   => canSendWhatsApp(),
            'isLicenseManager'  => isLicenseManager(),
            'isLicenseInspector'=> isLicenseInspector(),
            'isAdmin'           => isAdmin(),
            'isSuperAdmin'      => isSuperAdmin(),
            'hasSignature'      => hasSignature()
        ]
    ];
} else {
    $response = [
        'success' => false,
        'message' => 'User not authenticated.',
        'user_data' => null,
        'permissions' => [
            'canAdd'            => false,
            'canEdit'           => false,
            'canDelete'         => false,
            'canSendWhatsApp'   => false,
            'isLicenseManager'  => false,
            'isLicenseInspector'=> false,
            'isAdmin'           => false,
            'isSuperAdmin'      => false,
            'hasSignature'      => false
        ]
    ];
}

// 6. طباعة الاستجابة
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>
