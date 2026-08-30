<?php
require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
$textbotlang = languagechange();

$infoFile = __DIR__ . '/info';
$usersFile = __DIR__ . '/users.json';
$cancelFile = __DIR__ . '/sendmessage.cancel';
$lockFile = __DIR__ . '/sendmessage.lock';

// Only one worker may read/update the queue at a time. This also prevents a
// cancelled queue from being written back by an older overlapping process.
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    return;
}

if (is_file($cancelFile)) {
    @unlink($usersFile);
    @unlink($infoFile);
    @unlink($cancelFile);
    return;
}

if(!is_file($infoFile))return;
if(!is_file($usersFile))return;


$userid = json_decode(file_get_contents($usersFile));
if (!is_array($userid)) {
    error_log('Invalid broadcast queue JSON: ' . $usersFile);
    return;
}
if(is_file($infoFile)){
$info = json_decode(file_get_contents($infoFile),true);
if (!is_array($info)) {
    error_log('Invalid broadcast metadata JSON: ' . $infoFile);
    return;
}
}
$count = 0;
if(count($userid) == 0){
    if(isset($info['id_admin'])){
    deletemessage($info['id_admin'], $info['id_message']);
    sendmessage($info['id_admin'], $textbotlang['hardcoded']['bulkMessageDone'], null, 'HTML');
    unlink($infoFile);
    unlink($usersFile);
    }
    return;
    
}
$count_remein = count($userid);
$textprocces = sprintf($textbotlang['hardcoded']['bulkMessageProgress'], $count_remein);
$cancelmessage = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['keyboard']['cancelOperation'], 'callback_data' => 'cancel_sendmessage'],
            ],
        ]
    ]);
Editmessagetext($info['id_admin'], $info['id_message'],$textprocces, $cancelmessage);
$keyboardbuy = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['textbot']['sell'], 'callback_data' => 'buy'],
            ],
        ]
    ]);
$keyboardstart = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['keyboard']['start'], 'callback_data' => 'start'],
            ],
        ]
    ]);
$keyboardusertest = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['textbot']['userTest'], 'callback_data' => 'usertestbtn'],
            ],
        ]
    ]);
$keyboardhelpbtn = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['textbot']['help'], 'callback_data' => 'helpbtn'],
            ],
        ]
    ]);
$keyboardaffiliates = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['textbot']['affiliates'], 'callback_data' => 'affiliatesbtn'],
            ],
        ]
    ]);
$keyboardaddbalance = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['textbot']['addBalance'], 'callback_data' => 'Add_Balance'],
            ],
        ]
    ]);
for ($i = 0, $batchSize = min(20, count($userid)); $i < $batchSize; $i++) {
    if (is_file($cancelFile)) {
        @unlink($usersFile);
        @unlink($infoFile);
        @unlink($cancelFile);
        return;
    }

    $iduser = array_shift($userid);
    if ($info['type'] == "unpinmessage") {
        unpinmessage($iduser->id);
    } elseif ($info['type'] == "sendmessage" or $info['type'] == "xdaynotmessage") {
        if ($info['btnmessage'] == "none") {
            $meesage = sendmessage($iduser->id, $info['message'], null, 'HTML');
        } elseif ($info['btnmessage'] == "buy") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardbuy, 'HTML');
        } elseif ($info['btnmessage'] == "start") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardstart, 'HTML');
        } elseif ($info['btnmessage'] == "usertestbtn") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardusertest, 'HTML');
        } elseif ($info['btnmessage'] == "helpbtn") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardhelpbtn, 'HTML');
        } elseif ($info['btnmessage'] == "affiliatesbtn") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardaffiliates, 'HTML');
        } elseif ($info['btnmessage'] == "addbalance") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardaddbalance, 'HTML');
        }

        if ($meesage['ok'] == false and $meesage['description'] == "Forbidden: bot was blocked by the user") {
            $invoicecount = select("invoice", "*", "id_user", $iduser->id, "count");
            $userinfo = select("user", "Balance", "id", $iduser->id, "select");
            if ($invoicecount == 0 and $userinfo['Balance'] == 0) {
                $Id_user = $iduser->id;
                $stmt = $pdo->prepare("DELETE FROM user WHERE id = ?");
                $stmt->execute([$Id_user]);
            }
        }

        if ($meesage['ok'] and $info['pingmessage'] == "yes") {
            pinmessage($iduser->id, $meesage['result']['message_id']);
        }
    } elseif ($info['type'] == "forwardmessage") {
        $meesage = forwardMessage($info['id_admin'], $info['message'], $iduser->id);
        if ($meesage['ok'] and $info['pingmessage'] == "yes") {
            pinmessage($iduser->id, $meesage['result']['message_id']);
        }
    }
}

if (!is_file($cancelFile)) {
    $temporaryFile = $usersFile . '.tmp';
    file_put_contents($temporaryFile, json_encode($userid), LOCK_EX);
    rename($temporaryFile, $usersFile);
} else {
    @unlink($usersFile);
    @unlink($infoFile);
    @unlink($cancelFile);
}
