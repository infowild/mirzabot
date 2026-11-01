<?php
// Offline payload/integration tests. No config, database, network or real users.
error_reporting(E_ALL);
date_default_timezone_set(in_array('--utc', $argv, true) ? 'UTC' : 'Asia/Tehran');
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
function loadFunction($file, $name) {
    $code = source($file);
    $start = strpos($code, 'function ' . $name . '(');
    check($start !== false, 'test function exists');
    $tokens = token_get_all('<?php ' . substr($code, $start));
    $function = '';
    $depth = 0;
    $opened = false;
    foreach (array_slice($tokens, 1) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $function .= $text;
        if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            $opened = true;
        } elseif ($text === '}' && --$depth === 0 && $opened) {
            eval($function);
            return;
        }
    }
    throw new RuntimeException('Incomplete function');
}
foreach (['x-ui_single.php', 'panels.php'] as $file) {
    $code = preg_replace('/^require_once .*;\s*$/m', '', source($file));
    $code = preg_replace('/^ini_set\(.*;\s*$/m', '', $code);
    eval(substr($code, 5));
}
loadFunction('Marzban.php', 'adduser');
loadFunction('marzneshin.php', 'adduserm');
function select($table, $fields, $key, $value, $type = 'select') {
    global $panel;
    if ($table === 'invoice') return $type === 'fetchAll' ? [] : false;
    return $table === 'product' ? ['inbounds' => null] : $panel;
}
function token_panel($code) { return ['access_token' => 'fixture']; }
function token_panelm($code) { return ['access_token' => 'fixture']; }
function generateUUID() { return 'fixture-uuid'; }
function outputlink($url) { return 'vless://fixture'; }
function isBase64($value) { return false; }
class CurlRequest {
    private $url;
    function __construct($url) { $this->url = $url; }
    function setHeaders($headers) {}
    function setBearerToken($token) {}
    function post($body) {
        global $payload;
        $payload = json_decode($body, true);
        return ['status' => 200, 'error' => '', 'body' => json_encode([
            'success' => true, 'obj' => [], 'username' => 'testservice',
            'subscription_url' => 'https://subscription.example/sub/fixture',
            'links' => 'vless://fixture', 'expire' => '2030-12-31T12:34:56',
        ])];
    }
    function get() {
        global $payload;
        if (str_contains($this->url, '/clients/get/')) {
            return ['status' => 200, 'error' => '', 'body' => json_encode(['success' => true,
                'obj' => ['client' => $payload['client'], 'inboundIds' => $payload['inboundIds']]])];
        }
        if (str_contains($this->url, '/clients/traffic/')) {
            return ['status' => 200, 'error' => '', 'body' => json_encode(['success' => true,
                'obj' => ['total' => $payload['client']['totalGB'], 'expiryTime' => $payload['client']['expiryTime'], 'up' => 0, 'down' => 0]])];
        }
        return ['status' => 200, 'error' => '', 'body' => json_encode(['success' => true, 'obj' => ['vless://fixture']])];
    }
}
$domainhosts = 'bot.example:8443';
$textbotlang = ['extracted' => ['index_php' => ['customVolumeButton' => 'custom']]];
$manager = new ManagePanel();
$deadline = time() + 7 * 86400 + 3821;
$cases = 0;
foreach (['x-ui_single', 'marzban0', 'marzban1', 'marzneshin'] as $kind) {
    foreach (['0', '1'] as $holdTest) {
        foreach (['offconecton', 'onconecton'] as $connection) {
            foreach ([$deadline, time() + 123, 0] as $expiry) {
                foreach (['change_location', 'usertest'] as $operation) {
                    $panel = ['type' => str_starts_with($kind, 'marzban') ? 'marzban' : $kind,
                        'name_panel' => 'destination', 'code_panel' => 'fixture',
                        'url_panel' => 'https://panel.example', 'password_panel' => 'fixture',
                        'subvip' => 'offsubvip', 'linksubx' => 'https://subscription.example/sub',
                        'on_hold_test' => $holdTest, 'conecton' => $connection,
                        'version_panel' => $kind === 'marzban1' ? '1' : '0',
                        'inbounds' => null, 'inboundid' => '3,7', 'proxies' => '[]'];
                    $result = $manager->createUser('destination', 'usertest', 'testservice', [
                        'expire' => $expiry, 'data_limit' => 123456789,
                        'from_id' => 123, 'username' => 'fixture-user', 'type' => $operation]);
                    check($result['status'] === 'successful', 'creation integration succeeds');
                    $onHold = $operation === 'usertest' && $holdTest === '1' && $expiry !== 0;
                    if ($kind === 'x-ui_single') {
                        if ($onHold) {
                            check($payload['client']['expiryTime'] < 0, 'ordinary trial remains on hold');
                        } else {
                            check($payload['client']['expiryTime'] === $expiry * 1000, 'transfer preserves exact millisecond deadline');
                        }
                        check($payload['client']['totalGB'] === 123456789 && $payload['inboundIds'] === [3, 7], 'volume and inbounds preserved');
                        check($payload['client']['email'] === 'testservice' && $payload['client']['enable'] === true, 'client identity and enabled state preserved');
                        $readback = $manager->DataUser('destination', 'testservice');
                        if ($operation === 'change_location') {
                            check($readback['expire'] == $expiry && $readback['status'] === 'active', 'transferred xui service reads back with same active deadline');
                        } elseif ($onHold) {
                            check($readback['status'] === 'on_hold', 'ordinary unused trial remains on hold on readback');
                        }
                    } elseif (str_starts_with($kind, 'marzban')) {
                        if ($onHold) {
                            check($payload['expire'] === 0 && $payload['status'] === 'on_hold', 'ordinary Marzban trial policy unchanged');
                        } else {
                            $actual = is_string($payload['expire']) ? strtotime($payload['expire']) : $payload['expire'];
                            check($actual === $expiry, 'Marzban transfer deadline preserved');
                            check(!isset($payload['on_hold_expire_duration']), 'transfer must not restart first-use duration');
                        }
                        check($payload['data_limit'] === 123456789, 'Marzban volume preserved');
                    } else {
                        $strategy = $onHold ? 'start_on_first_use' : ($expiry === 0 ? 'never' : 'fixed_date');
                        // Existing unlimited trial policy omits strategy when hold is on.
                        if (!($operation === 'usertest' && $holdTest === '1' && $expiry === 0)) {
                            check(($payload['expire_strategy'] ?? '') === $strategy, 'Marzneshin expiry strategy preserved');
                        }
                        if (!$onHold && $expiry !== 0) {
                            check(strtotime($payload['expire_date']) === $expiry, 'Marzneshin exact deadline preserved');
                        }
                        check($payload['data_limit'] === 123456789, 'Marzneshin volume preserved');
                    }
                    $cases++;
                }
            }
        }
    }
}
// Non-trial calls use the normal first-use policy unless explicitly transferring.
foreach (['offconecton', 'onconecton'] as $connection) {
    $panel['conecton'] = $connection;
    addClient('destination', 'testservice', $deadline, 100, 'uuid', '', 'subid', 3, 'paid');
    check($connection === 'onconecton' ? $payload['client']['expiryTime'] < 0 : $payload['client']['expiryTime'] === $deadline * 1000, 'paid xui purchase policy unchanged');
    foreach (['0', '1'] as $version) {
        $panel['version_panel'] = $version;
        adduser('destination', 100, 'testservice', $deadline, '', 'no_reset', false);
        check($connection === 'onconecton' ? $payload['status'] === 'on_hold' : strtotime((string) $payload['expire']) === $deadline || $payload['expire'] === $deadline, 'paid Marzban policy unchanged');
    }
    adduserm('destination', 100, 'testservice', $deadline, 'paid');
    check($payload['expire_strategy'] === ($connection === 'onconecton' ? 'start_on_first_use' : 'fixed_date'), 'paid Marzneshin policy unchanged');
    $cases += 4;
    addClient('destination', 'testservice', $deadline, 100, 'uuid', '', 'subid', 3, 'paid', '', true);
    check($payload['client']['expiryTime'] === $deadline * 1000, 'explicit paid transfer keeps deadline regardless of connection policy');
    foreach (['0', '1'] as $version) {
        $panel['version_panel'] = $version;
        adduser('destination', 100, 'testservice', $deadline, '', 'no_reset', false, true);
        $actual = is_string($payload['expire']) ? strtotime($payload['expire']) : $payload['expire'];
        check($actual === $deadline && !isset($payload['on_hold_expire_duration']), 'paid Marzban transfer deadline preserved');
    }
    adduserm('destination', 100, 'testservice', $deadline, 'paid', '', 'no_reset', true);
    check($payload['expire_strategy'] === 'fixed_date' && strtotime($payload['expire_date']) === $deadline, 'paid Marzneshin transfer deadline preserved');
    $cases += 4;
}
$index = source('index.php');
check(preg_match('/\$datac = array\(\s*\x27expire\x27 => \$DataUserOut\[\x27expire\x27\].*?\x27type\x27 => \x27change_location\x27/s', $index) === 1, 'location handler must enable expiry preservation');
echo 'PASS: ' . $cases . " expiry/purchase integration cases and transfer-handler wiring\n";
