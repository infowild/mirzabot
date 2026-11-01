<?php
// Exercises the actual list SQL and callback guards without a live panel.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function check($ok, $label) {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
}
function source($file) {
    global $argv;
    return in_array('--baseline', $argv, true) ? shell_exec('git show HEAD:' . $file) : file_get_contents(__DIR__ . '/../' . $file);
}
function section($text, $start, $end) {
    $offset = strpos($text, $start);
    check($offset !== false, 'start marker found');
    $offset += strlen($start);
    $length = strpos($text, $end, $offset);
    check($length !== false, 'end marker found');
    return substr($text, $offset, $length - $offset);
}
$keyboardSource = source('keyboard.php');
$listSource = section($keyboardSource, '//------------------  [ change location ]----------------//', '//------------------  [ listpanelusers test ]----------------//');
// Load the shared guard once, then execute the list block repeatedly.
if (strpos($listSource, 'function isChangeLocationDestinationEnabled') !== false) {
    $functionStart = strpos($listSource, 'function isChangeLocationDestinationEnabled');
    $queryStart = strpos($listSource, '$stmt = $pdo->prepare(');
    eval(substr($listSource, $functionStart, $queryStart - $functionStart));
    $listSource = substr($listSource, $queryStart);
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE marzban_panel (name_panel TEXT, code_panel TEXT, status TEXT, agent TEXT, changeloc TEXT, hide_user TEXT)');
function panel($code, array $changes = []) {
    return array_merge(['name_panel' => $code, 'code_panel' => $code, 'status' => 'active',
        'agent' => 'all', 'changeloc' => 'onchangeloc', 'hide_user' => null, 'priceChangeloc' => 321], $changes);
}
function select($table, $fields, $key, $value, $type = 'select', $options = []) {
    global $pdo, $callbackPanel, $sourcePanel, $readOptions;
    if ($table === 'invoice') return ['Service_location' => 'source', 'id_invoice' => 'fixture-invoice'];
    if ($type === 'count') return (int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE status='active'")->fetchColumn();
    $readOptions[] = $options;
    return $key === 'code_panel' ? $callbackPanel : $sourcePanel;
}
function update(...$args) { global $writes; $writes[] = $args; }
function sendmessage(...$args) { global $messages; $messages[] = $args; }
function Editmessagetext(...$args) { global $edits; $edits[] = $args; }

$basePanels = [panel('source'), panel('allowed'), panel('disabled-change', ['changeloc' => 'offchangeloc']),
    panel('inactive', ['status' => 'disable']), panel('other-agent', ['agent' => 'n2']),
    panel('hidden', ['hide_user' => '[123]']), panel('hidden-string', ['hide_user' => '["123"]']),
    panel('same-agent', ['agent' => 'f']), panel('hidden-elsewhere', ['hide_user' => '[999]'])];
$from_id = 123;
$users = ['agent' => 'f', 'Processing_value_four' => 'source'];
$textbotlang = ['users' => ['backbtn' => 'Back']];
$cases = 0;
foreach ([0, 12] as $extraCount) {
    $pdo->exec('DELETE FROM marzban_panel');
    $insert = $pdo->prepare('INSERT INTO marzban_panel VALUES (?,?,?,?,?,?)');
    foreach ($basePanels as $row) $insert->execute(array_values(array_intersect_key($row, array_flip(['name_panel','code_panel','status','agent','changeloc','hide_user']))));
    for ($n = 0; $n < $extraCount; $n++) {
        $row = panel('filler-' . $n, ['changeloc' => 'offchangeloc']);
        $insert->execute(array_values(array_intersect_key($row, array_flip(['name_panel','code_panel','status','agent','changeloc','hide_user']))));
    }
    eval($listSource);
    $buttons = array_merge(...$list_marzban_panel_users_change['inline_keyboard']);
    $callbacks = array_column($buttons, 'callback_data');
    check($callbacks === ['changelocselectlo-allowed', 'changelocselectlo-same-agent', 'changelocselectlo-hidden-elsewhere', 'backorder'], 'only enabled, visible and eligible destinations appear in either layout');
    $expectedWidth = $extraCount > 0 ? 2 : 1;
    check(count($list_marzban_panel_users_change['inline_keyboard'][0]) === $expectedWidth, 'existing layout preserved');
    $pdo->exec("UPDATE marzban_panel SET changeloc='offchangeloc' WHERE code_panel='allowed'");
    eval($listSource);
    $buttons = array_merge(...$list_marzban_panel_users_change['inline_keyboard']);
    check(!in_array('changelocselectlo-allowed', array_column($buttons, 'callback_data'), true), 'turning capability off removes destination');
    $pdo->exec("UPDATE marzban_panel SET changeloc='onchangeloc' WHERE code_panel='allowed'");
    eval($listSource);
    $buttons = array_merge(...$list_marzban_panel_users_change['inline_keyboard']);
    check(in_array('changelocselectlo-allowed', array_column($buttons, 'callback_data'), true), 'turning capability back on restores destination');
    $pdo->exec("UPDATE marzban_panel SET changeloc='offchangeloc'");
    eval($listSource);
    check($list_marzban_panel_users_change['inline_keyboard'] === [[['text' => 'Back', 'callback_data' => 'backorder']]], 'empty destination list retains back button');
    $cases += 5;
}

$indexSource = source('index.php');
$selectionSource = section($indexSource, "} elseif (preg_match('/changelocselectlo-(\\w+)/', \$datain, \$dataget)) {", "} elseif (preg_match('/confirmchangeloccha_(\\w+)/', \$datain, \$dataget)) {");
$confirmSource = section($indexSource, "} elseif (preg_match('/confirmchangeloccha_(\\w+)/', \$datain, \$dataget)) {", '    $Pricechange = $marzban_list_get_new[\'priceChangeloc\'];');
function runSelection() {
    global $selectionSource, $user, $from_id, $textbotlang, $dataget, $setting, $message_id;
    eval($selectionSource);
}
function runConfirmation() {
    global $confirmSource, $user, $from_id, $textbotlang, $dataget, $setting, $confirmationReached;
    eval($confirmSource . '; $confirmationReached = true;');
}
$textbotlang = ['extracted' => ['index_php' => ['featureUnavailableDot' => 'Unavailable', 'genericProcessRestart' => 'Restart', 'locationChangeLimitReached' => 'Limit']],
    'hardcoded' => ['changeLocationConfirmPrompt' => '%s %s %s'], 'Admin' => ['changeLocation' => ['confirm' => 'Confirm']],
    'users' => ['status' => ['backinfo' => 'Back']]];
$user = ['agent' => 'f', 'Processing_value' => 'fixture-invoice', 'Processing_value_one' => 'allowed', 'limitchangeloc' => 0];
$setting = ['limitnumber' => '{"all":10,"free":2}', 'statuslimitchangeloc' => '0'];
$message_id = 1;
$dataget = ['', 'allowed'];
foreach ([false, panel('allowed'), panel('off', ['changeloc' => 'offchangeloc']), panel('inactive', ['status' => 'disable']),
    panel('other-agent', ['agent' => 'n2']), panel('hidden', ['hide_user' => '[123]']), panel('unknown', ['changeloc' => null])] as $candidate) {
    $callbackPanel = $candidate;
    $sourcePanel = panel('source');
    $writes = $messages = $edits = $readOptions = [];
    runSelection();
    $allowed = is_array($candidate) && $candidate['code_panel'] === 'allowed';
    check($allowed ? count($writes) === 1 && count($edits) === 1 : !$writes && !$edits && count($messages) === 1, 'selection rejects stale/unavailable destinations before state or price confirmation');
    check(($readOptions[0]['cache'] ?? null) === false, 'selection reads fresh capability');
    $confirmationReached = false;
    $writes = $messages = $edits = $readOptions = [];
    runConfirmation();
    check($confirmationReached === $allowed && !$writes, 'confirmation rechecks destination before service mutations');
    check(($readOptions[0]['cache'] ?? null) === false && ($readOptions[1]['cache'] ?? null) === false, 'confirmation reads fresh source and destination');
    $cases += 2;
}
foreach ([panel('source', ['changeloc' => 'offchangeloc']), panel('source')] as $sourcePanel) {
    $callbackPanel = $sourcePanel['changeloc'] === 'onchangeloc' ? panel('source') : panel('allowed');
    $writes = $messages = $edits = $readOptions = [];
    $confirmationReached = false;
    runConfirmation();
    check(!$confirmationReached && !$writes, 'disabled source or same-location stale confirmation rejected');
    $cases++;
}
$sourcePanel = panel('source');
$callbackPanel = panel('allowed');
$writes = $messages = $edits = $readOptions = [];
runSelection();
check(count($writes) === 1, 'enabled destination can be selected');
$callbackPanel['changeloc'] = 'offchangeloc';
$confirmationReached = false;
$writes = $messages = $edits = $readOptions = [];
runConfirmation();
check(!$confirmationReached && !$writes && count($messages) === 1, 'admin disabling destination between selection and confirmation prevents transfer');
$cases++;
echo 'PASS: ' . $cases . " list/toggle/selection/confirmation scenarios\n";
