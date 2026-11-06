<?php
// Offline UI/routing tests and existing wallet/cap handler integration.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require_once __DIR__ . '/../agent_credit.php';
function check($ok, $label) { if (!$ok) throw new RuntimeException('FAIL: ' . $label); }
function section($code, $start, $end) {
    $begin = strpos($code, $start);
    check($begin !== false, 'handler start exists');
    $begin += strlen($start);
    $finish = strpos($code, $end, $begin);
    check($finish !== false, 'handler end exists');
    return substr($code, $begin, $finish - $begin);
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE user (id INTEGER PRIMARY KEY,username TEXT,agent TEXT,Balance INTEGER,maxbuyagent INTEGER,step TEXT,Processing_value TEXT)');
$pdo->exec('CREATE TABLE Payment_report (id_user TEXT,id_order TEXT,time TEXT,price INTEGER,payment_Status TEXT,Payment_Method TEXT,id_invoice TEXT)');
$insert = $pdo->prepare("INSERT INTO user VALUES (?,?,?,?,?,'home','')");
$insert->execute([1, 'admin', 'f', 0, 0]);
$insert->execute([2, 'ordinary', 'f', 500, 0]);
for ($id = 100; $id <= 117; $id++) $insert->execute([$id, 'fixture-' . $id, $id % 2 ? 'n' : 'n2', 0, 1000000]);
$pdo->exec("UPDATE user SET username='<b>not markup</b>',Balance=-300000 WHERE id=100");
$pdo->exec('UPDATE user SET Balance=200000 WHERE id=102');
$pdo->exec('UPDATE user SET maxbuyagent=0 WHERE id=104');
$pdo->exec('UPDATE user SET Balance=-1200000 WHERE id=106');
function select($table, $fields, $key, $value, $type = 'select', $options = []) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT $fields FROM $table WHERE $key = :value");
    $stmt->execute([':value' => $value]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function update($table, $field, $value, $key, $where) {
    global $pdo, $updates;
    $updates[] = [$table, $field, $where, $value];
    $stmt = $pdo->prepare("UPDATE $table SET $field = :value WHERE $key = :where");
    $stmt->execute([':value' => $value, ':where' => $where]);
}
function step($value, $id) { update('user', 'step', $value, 'id', $id); }
function sendmessage(...$args) { global $messages; $messages[] = $args; }
function Editmessagetext(...$args) { global $edits; $edits[] = $args; }
function telegram(...$args) { global $notifications; $notifications[] = $args; }
class OfflineMysqli {
    function prepare($sql) { return new OfflineMysqliStatement($sql); }
}
class OfflineMysqliStatement {
    private $sql;
    private $values;
    function __construct($sql) { $this->sql = $sql; }
    function bind_param($types, &...$values) { $this->values = $values; }
    function execute() { global $pdo; $pdo->prepare($this->sql)->execute($this->values); }
}
$connect = new OfflineMysqli();
$from_id = 1;
$admin_ids = [1];
$adminrulecheck = ['rule' => 'administrator'];
$textbotlang = require __DIR__ . '/../lang/fa.php';
$datain = '';
$text = '';
$message_id = 99;
$updates = $messages = $edits = $notifications = [];
$user = select('user', '*', 'id', 1);
$snapshot = $pdo->query("SELECT * FROM user WHERE agent IN ('n','n2') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$checks = 0;
function assertCheck($ok, $label) { global $checks; check($ok, $label); $checks++; }
foreach (['fa','en','ru','zh'] as $lang) {
    $language = require __DIR__ . '/../lang/' . $lang . '.php';
    assertCheck(count($language['agentCredit']) === count(agentCreditLabels([])), 'complete localized labels');
    $page = agentCreditList($pdo, 0, $language);
    $callbacks = array_column(array_merge(...$page['keyboard']['inline_keyboard']), 'callback_data');
    assertCheck(count(array_filter($callbacks, fn($c) => str_starts_with($c, 'agentcredit_view_'))) === 8, 'agent pagination excludes ordinary users');
    $view = agentCreditView($pdo, '100', 0, $language);
    assertCheck(str_contains($view['text'], '&lt;b&gt;not markup&lt;/b&gt;') && !str_contains($view['text'], '<b>not markup</b>'), 'username escaped');
    assertCheck(str_contains($view['text'], '300,000') && str_contains($view['text'], '700,000'), 'debt and remaining purchasing capacity');
    $callbacks = array_column(array_merge(...$view['keyboard']['inline_keyboard']), 'callback_data');
    assertCheck(in_array('addbalanceuser_100', $callbacks, true) && in_array('lowbalanceuser_100', $callbacks, true) && in_array('maxbuyagent_100', $callbacks, true), 'existing audited handlers reused');
    foreach ($callbacks as $callback) assertCheck(strlen($callback) <= 64, 'Telegram callback size');
    $normal = agentCreditView($pdo, '101', 0, $language);
    assertCheck(!str_contains(json_encode($normal), 'maxbuyagent_101'), 'normal agents cannot receive advanced debt setting');
}
assertCheck(str_contains(agentCreditView($pdo, '102', 0, $textbotlang)['text'], '1,200,000'), 'positive wallet adds to credit capacity');
assertCheck(str_contains(agentCreditView($pdo, '104', 0, $textbotlang)['text'], $textbotlang['agentCredit']['unlimited']), 'zero cap remains unlimited');
assertCheck(str_contains(agentCreditView($pdo, '106', 0, $textbotlang)['text'], '0 ' . $textbotlang['agentCredit']['currency']), 'overdrawn account has zero buying capacity');
assertCheck(agentCreditView($pdo, '2', 0, $textbotlang) === null && agentCreditView($pdo, '999', 0, $textbotlang) === null, 'ordinary/missing users are not agents');
$last = agentCreditList($pdo, 999999, $textbotlang);
assertCheck(str_contains(json_encode($last), 'agentcredit_view_116_2') && !str_contains(json_encode($last), 'agentcredit_list_3'), 'out-of-range page clamps to last page');
foreach (['', 'some_other_action', 'agentcredit_invalid', 'agentcredit_view_2_0', 'agentcredit_view_100_0', 'agentcredit_list_0', 'agentcredit_search'] as $callback) {
    $datain = $callback;
    $user['step'] = 'home';
    $updates = $messages = $edits = [];
    $handled = handleAgentCreditManagement();
    assertCheck($handled === str_starts_with($callback, 'agentcredit_'), 'unrelated routes untouched');
}
foreach ([['administrator', []], ['support', [1]]] as [$role, $ids]) {
    $adminrulecheck['rule'] = $role;
    $admin_ids = $ids;
    $updates = $messages = $edits = [];
    $datain = 'agentcredit_view_100_0';
    assertCheck(handleAgentCreditManagement() && !$updates && !$messages && !$edits, 'unauthorized role cannot read or navigate agent finances');
}
$adminrulecheck['rule'] = 'administrator';
$admin_ids = [1];
$user['step'] = 'agent_credit_search';
$datain = '';
foreach (['0','-100','100 OR 1=1','1.5',str_repeat('9', 20),'999','2'] as $value) {
    $text = $value;
    $updates = $messages = [];
    assertCheck(handleAgentCreditManagement() && !$updates && count($messages) === 1, 'invalid/non-agent search never changes state or funds');
}
$text = $textbotlang['Admin']['backAdminBtn'];
assertCheck(handleAgentCreditManagement() === false, 'existing Back handler preserved');
$text = '100';
$messages = $updates = [];
assertCheck(handleAgentCreditManagement() && count($messages) === 1 && $updates[0][1] === 'step', 'valid search opens details and ends pending input');
assertCheck($snapshot === $pdo->query("SELECT * FROM user WHERE agent IN ('n','n2') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), 'all navigation and search leave agent data unchanged');

// Execute the untouched legacy handlers used by the new buttons.
$adminSource = file_get_contents(__DIR__ . '/../admin.php');
$capHandler = section($adminSource, '} elseif ($user[\'step\'] == "getmaxbuyagent") {', '} elseif ($datain == "searchorder") {');
$addHandler = section($adminSource, '} elseif ($user[\'step\'] == "addbalanceusercurrent") {', "} elseif (preg_match('/lowbalanceuser_(\\w+)/', \$datain, \$dataget)) {");
$subtractHandler = section($adminSource, '} elseif ($user[\'step\'] == "addbalanceuser") {', "} elseif ((preg_match('/banuserlist_(\\w+)/', \$datain, \$dataget) || preg_match('/blockuserfake_(\\w+)/', \$datain, \$dataget))) {");
function legacy($code) {
    global $text, $from_id, $user, $textbotlang, $backadmin, $keyboardadmin, $connect, $setting, $username;
    eval($code);
}
$backadmin = $keyboardadmin = '{}';
$setting = ['Channel_Report' => ''];
$username = 'fixture-admin';
$user = select('user', '*', 'id', 1);
$user['Processing_value'] = '100';
$text = '2000000';
legacy($capHandler);
assertCheck((int) select('user', '*', 'id', 100)['maxbuyagent'] === 2000000 && (int) select('user', '*', 'id', 100)['Balance'] === -300000, 'cap edit leaves actual balance and debt intact');
$text = '-1';
legacy($capHandler);
assertCheck((int) select('user', '*', 'id', 100)['maxbuyagent'] === 2000000, 'legacy negative cap rejected');
$text = '0';
legacy($capHandler);
assertCheck((int) select('user', '*', 'id', 100)['maxbuyagent'] === 0, 'legacy unlimited cap accepted');
$text = '500000';
legacy($addHandler);
assertCheck((int) select('user', '*', 'id', 100)['Balance'] === 200000, 'manual credit first pays off debt');
$text = '100000';
legacy($subtractHandler);
assertCheck((int) select('user', '*', 'id', 100)['Balance'] === 100000, 'manual debit uses existing workflow');
assertCheck((int) $pdo->query('SELECT COUNT(*) FROM Payment_report')->fetchColumn() === 2, 'existing payment reports recorded for add/debit');
$text = 'invalid';
legacy($addHandler);
assertCheck((int) select('user', '*', 'id', 100)['Balance'] === 100000, 'invalid wallet amount does not change balance');
$text = '100000001';
legacy($addHandler);
assertCheck((int) select('user', '*', 'id', 100)['Balance'] === 100000 && (int) $pdo->query('SELECT COUNT(*) FROM Payment_report')->fetchColumn() === 2, 'existing per-operation wallet limit preserved');
$pdo->exec("UPDATE user SET agent='f' WHERE id=100");
assertCheck(agentCreditView($pdo, '100', 0, $textbotlang) === null, 'stale agent view rejected after role change');
$pdo->exec("DELETE FROM user WHERE agent IN ('n','n2')");
assertCheck(agentCreditList($pdo, 0, $textbotlang)['text'] === $textbotlang['agentCredit']['empty'], 'empty list supported');
assertCheck(str_contains($adminSource, "'callback_data' => 'agentcredit_list_0'"), 'dedicated menu wired into agents');
$menu = section($adminSource, '} elseif ($datain == "agentlistusers") {', "} elseif (preg_match('/agenttypshowlist_(\\w+)/', \$datain, \$datagetr)) {");
foreach (['administrator', 'support'] as $role) {
    $adminrulecheck['rule'] = $role;
    $edits = [];
    eval($menu);
    $buttons = array_column(array_merge(...json_decode($edits[0][3], true)['inline_keyboard']), 'callback_data');
    assertCheck(in_array('agentcredit_list_0', $buttons, true) === ($role === 'administrator'), 'menu shown only to authorized administrator');
    assertCheck(in_array('agenttypshowlist_n', $buttons, true) && in_array('agenttypshowlist_n2', $buttons, true) && in_array('agenttypshowlist_all', $buttons, true), 'existing agents menu links unchanged');
}
echo "PASS: $checks agent-credit UI/routing/legacy-operation assertions\n";
