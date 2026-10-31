<?php
// Offline regression tests: no config, credentials, database or network access.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$baseline = in_array('--baseline', $argv, true);
foreach (['x-ui_single.php', 'panels.php'] as $file) {
    $source = $baseline ? shell_exec('git show HEAD:' . $file) : file_get_contents(__DIR__ . '/../' . $file);
    $source = preg_replace('/^require_once .*;\s*$/m', '', $source);
    $source = preg_replace('/^ini_set\(.*;\s*$/m', '', $source);
    eval(substr($source, 5));
}

function check($condition, $label) {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
}
function select($table, $fields, $key, $value, $type = 'select') {
    global $panel, $invoices;
    if ($table === 'marzban_panel') return $panel;
    $rows = array_values(array_filter($invoices, fn($row) => $row[$key] === $value));
    return $type === 'fetchAll' ? $rows : ($rows[0] ?? false);
}
function clearSelectCache($table) {}
function update($table, $field, $value, $key, $id) {
    global $writes;
    $writes[] = ['legacy-update'];
}
class FakeDatabase {
    function prepare($query) { return $this; }
    function execute($args) {
        global $writes;
        $writes[] = $args;
    }
}
class CurlRequest {
    private $url;
    function __construct($url) { $this->url = $url; }
    function setHeaders($headers) {}
    function get() {
        global $client, $searchItems, $readbackFails, $posts, $searchPages, $apiLinksFail, $publicBody;
        if (str_contains($this->url, '/clients/get/')) {
            if ($readbackFails && $posts) return ['status' => 503, 'body' => '', 'error' => ''];
            return response(['success' => true, 'obj' => $client]);
        }
        if (str_contains($this->url, '/clients/traffic/')) {
            return response(['success' => true, 'obj' => ['total' => 999, 'expiryTime' => 0]]);
        }
        if (str_contains($this->url, '/clients/search?')) {
            parse_str(parse_url($this->url, PHP_URL_QUERY), $query);
            return response(['success' => true, 'obj' => ['items' => $searchPages[$query['page']] ?? $searchItems]]);
        }
        if (str_contains($this->url, '/clients/subLinks/')) {
            if ($apiLinksFail) return response(['success' => false], 404);
            return response(['success' => true, 'obj' => ['vless://example-test']]);
        }
        if (str_starts_with($this->url, 'https://subscription.example:8443/custom/sub/')) {
            return ['status' => 200, 'body' => $publicBody, 'error' => ''];
        }
        throw new RuntimeException('Unexpected request');
    }
    function post($body) {
        global $client, $posts, $postStatus, $postSuccess;
        $payload = json_decode($body, true);
        $posts[] = $payload;
        if ($postStatus === 200 && $postSuccess) $client['subId'] = $payload['subId'];
        return response(['success' => $postSuccess], $postStatus);
    }
}
function response($body, $status = 200) {
    return ['status' => $status, 'body' => json_encode($body), 'error' => ''];
}
function resetScenario() {
    global $panel, $invoices, $client, $searchItems, $posts, $writes,
        $postStatus, $postSuccess, $readbackFails, $pdo, $domainhosts, $searchPages, $apiLinksFail, $publicBody;
    $pdo = new FakeDatabase();
    $domainhosts = 'bot.example:8443';
    $panel = ['name_panel' => 'restored', 'type' => 'x-ui_single',
        'url_panel' => 'https://panel.example', 'password_panel' => 'test-only',
        'linksubx' => 'https://subscription.example:8443/custom/sub/', 'subvip' => 'offsubvip'];
    $invoices = [
        ['username' => 'legacy', 'Service_location' => 'original', 'uuid' => 'other-location', 'id_invoice' => 'invoice-other'],
        ['username' => 'legacy', 'Service_location' => 'restored', 'uuid' => 'stale-id', 'id_invoice' => 'invoice-restored'],
    ];
    $client = ['email' => 'legacy', 'enable' => true, 'totalGB' => 123,
        'expiryTime' => -86400000, 'subId' => 'panel-current', 'comment' => 'preserve-me'];
    $searchItems = $posts = $writes = [];
    $postStatus = 200;
    $postSuccess = true;
    $readbackFails = false;
    $searchPages = [];
    $apiLinksFail = false;
    $publicBody = '';
}
$manager = new ManagePanel();
resetScenario();
$result = $manager->DataUser('restored', 'legacy');
check($result['subscription_url'] === $panel['linksubx'] . 'panel-current', 'panel ID must override stale invoice ID');
check(count($writes) === 1 && $writes[0][':location'] === 'restored', 'cache update must be scoped to location');
check(!$posts, 'existing clients must not be modified');

resetScenario();
$panel['subvip'] = 'onsubvip';
$result = $manager->DataUser('restored', 'legacy');
check($result['subscription_url'] === 'https://bot.example:8443/sub/invoice-restored', 'VIP must use invoice from requested location');

resetScenario();
unset($client['subId']);
$searchItems = [['email' => 'different', 'subId' => 'wrong'], ['email' => 'legacy', 'subId' => 'search-current']];
$result = $manager->DataUser('restored', 'legacy');
check(str_ends_with($result['subscription_url'], '/search-current'), 'search must match exact email');
check(!$posts, 'search-resolved client must not be modified');

resetScenario();
unset($client['subId']);
$result = $manager->DataUser('restored', 'legacy');
check($result['status'] === 'Unsuccessful' && !$posts && !$writes, 'unknown ID must not cause guessed links or writes');

foreach ([503, 200] as $status) {
    resetScenario();
    $client['subId'] = '';
    $postStatus = $status;
    $postSuccess = false;
    $result = $manager->DataUser('restored', 'legacy');
    check($result['status'] === 'Unsuccessful' && !$writes, 'failed update must not be cached or delivered');
}

resetScenario();
$client['subId'] = '';
$readbackFails = true;
$result = $manager->DataUser('restored', 'legacy');
check($result['status'] === 'Unsuccessful' && !$writes, 'failed readback must not deliver unverified ID');

resetScenario();
$client['subId'] = '';
$client['enable'] = false;
$result = $manager->DataUser('restored', 'legacy');
check(str_ends_with($result['subscription_url'], '/' . $client['subId']), 'migration must deliver read-back ID');
check($posts[0]['enable'] === false && $posts[0]['totalGB'] === 123 &&
    $posts[0]['expiryTime'] === -86400000 && $posts[0]['comment'] === 'preserve-me', 'migration must preserve original client settings');

resetScenario();
$client = [['email' => 'someone-else', 'subId' => 'wrong'], $client];
$result = $manager->DataUser('restored', 'legacy');
check(str_ends_with($result['subscription_url'], '/panel-current'), 'array response must select exact client');

resetScenario();
$client['email'] = 'someone-else';
$result = $manager->DataUser('restored', 'legacy');
check($result['status'] === 'Unsuccessful' && !$posts && !$writes, 'wrong client must be rejected');

resetScenario();
unset($client['subId']);
$searchPages = [1 => array_fill(0, 25, ['email' => 'legacy-other', 'subId' => 'wrong']),
    2 => [['email' => 'legacy', 'subId' => 'page-two']]];
$result = $manager->DataUser('restored', 'legacy');
check(str_ends_with($result['subscription_url'], '/page-two'), 'legacy lookup must follow search pagination');

foreach (["vless://example-a\nvmess://example-b", base64_encode("vless://example-a\nvmess://example-b")] as $body) {
    resetScenario();
    $apiLinksFail = true;
    $publicBody = $body;
    $result = $manager->DataUser('restored', 'legacy');
    check($result['links'] === ['vless://example-a', 'vmess://example-b'], 'public subscription must supply links when subLinks is unavailable');
}

resetScenario();
$apiLinksFail = true;
$publicBody = '<html>Login required</html>';
$result = $manager->DataUser('restored', 'legacy');
check($result['links'] === [], 'HTML must not be delivered as configuration');

resetScenario();
$panel['linksubx'] = '';
$result = $manager->DataUser('restored', 'legacy');
check($result['status'] === 'Unsuccessful' && !$posts && !$writes, 'missing subscription domain must not create a relative URL');
echo "PASS: 15 offline subscription regression scenarios\n";
