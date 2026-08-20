<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../botapi.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Tehran');
ini_set('default_charset', 'UTF-8');
ini_set('error_log', 'error_log');

$headrs = getallheaders();
$setting = select("setting", "*");
if (!apiValidateAdminToken($headrs)) {
    http_response_code(403);
    echo json_encode(array(
        'status' => false,
        'msg' => "token invalid"
        ));
    return;
}

apiLogRequest($pdo, $headrs, [], 'log');


$count_user = select("user","*",null,null,"count");
$stmt = $pdo->prepare("SELECT * FROM user WHERE agent != 'f'");
$stmt->execute();
$count_agent = $stmt->rowCount();
$count_invoice = select("invoice","*",null,null,"count");
echo json_encode(array(
    'count_user' => $count_user,
    'count_invoice' => $count_invoice,
    'count_agent' => $count_agent
    ));
