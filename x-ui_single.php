<?php
require_once 'config.php';
require_once 'request.php';
ini_set('error_log', 'error_log');

function bearer_headers_xui($namepanel)
{
    $panel = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    return array(
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $panel['password_panel'],
    );
}

function get_clinets($username, $namepanel)
{
    $panel   = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $headers = bearer_headers_xui($namepanel);

    // 1) Fetch client config: enable, totalGB, expiryTime, subId, inboundIds
    $url_get = $panel['url_panel'] . "/panel/api/clients/get/" . urlencode($username);
    $req_get = new CurlRequest($url_get);
    $req_get->setHeaders($headers);
    $resp_get = $req_get->get();

    if (!empty($resp_get['error'])) {
        return $resp_get;
    }
    if ($resp_get['status'] != 200) {
        return $resp_get;
    }

    $data_get = json_decode($resp_get['body'], true);
    if (!$data_get || !isset($data_get['success'])) {
        return array('status' => 500, 'body' => $resp_get['body'], 'error' => 'Invalid response from panel');
    }
    if (!$data_get['success'] || empty($data_get['obj'])) {
        return array('status' => 200, 'body' => json_encode(array('success' => false, 'msg' => $data_get['msg'] ?? 'User not found')), 'error' => '');
    }

    // Handle both single-object and array-of-objects in obj
    $c = $data_get['obj'];
    // This panel returns obj.client plus sibling metadata, not a flat client.
    if (is_array($c) && isset($c['client']) && is_array($c['client'])) {
        $wrapper = $c;
        $c = $wrapper['client'];
        $c['inboundIds'] = $c['inboundIds'] ?? $wrapper['inboundIds'] ?? [];
    }
    if (isset($c[0]) && is_array($c[0])) {
        $matches = array_values(array_filter($c, function ($item) use ($username) {
            return is_array($item) && ($item['email'] ?? '') === $username;
        }));
        $c = $matches[0] ?? [];
    }
    if (!is_array($c) || ($c['email'] ?? '') !== $username) {
        return ['status' => 502, 'error' => 'Panel returned a different client', 'body' => ''];
    }

    // 2) Fetch traffic counters: up, down, total, expiryTime
    // traffic endpoint is authoritative for total and expiryTime
    $url_traffic = $panel['url_panel'] . "/panel/api/clients/traffic/" . urlencode($username);
    $req_traffic = new CurlRequest($url_traffic);
    $req_traffic->setHeaders($headers);
    $resp_traffic = $req_traffic->get();

    $up   = 0;
    $down = 0;
    $total      = $c['totalGB']    ?? 0;
    $expiryTime = $c['expiryTime'] ?? 0;
    if (empty($resp_traffic['error']) && $resp_traffic['status'] == 200) {
        $data_traffic = json_decode($resp_traffic['body'], true);
        if ($data_traffic && !empty($data_traffic['obj'])) {
            $up         = $data_traffic['obj']['up']         ?? 0;
            $down       = $data_traffic['obj']['down']       ?? 0;
            $total      = $data_traffic['obj']['total']      ?? $total;
            $expiryTime = $data_traffic['obj']['expiryTime'] ?? $expiryTime;
        }
    }

    // Resolve the panel's current ID, never an invoice's cached ID.
    $subId = $c['subId'] ?? '';
    $subIdKnown = array_key_exists('subId', $c);

    // Fallback 1: search endpoint (filters by exact email match)
    for ($page = 1; empty($subId) && $page <= 100; $page++) {
        $search_qs = http_build_query([
            'search'   => $username,
            'page'     => $page,
            'size'     => 25,
            'filter'   => '',
            'protocol' => '',
            'sort'     => 'email',
            'order'    => 'ascend',
        ]);
        $req_search = new CurlRequest($panel['url_panel'] . "/panel/api/clients/search?" . $search_qs);
        $req_search->setHeaders($headers);
        $resp_search = $req_search->get();
        if (empty($resp_search['error']) && $resp_search['status'] == 200) {
            $data_search = json_decode($resp_search['body'], true);
            $items = !empty($data_search['success']) ? ($data_search['obj']['items'] ?? []) : [];
            $matched = false;
            foreach ($items as $item) {
                if (($item['email'] ?? '') === $username && array_key_exists('subId', $item)) {
                    $subId = $item['subId'];
                    $subIdKnown = true;
                    $matched = true;
                    break;
                }
            }
            if ($matched || count($items) < 25) {
                break;
            }
        } else {
            break;
        }
    }

    // 4) Merge into a single obj compatible with panels.php expectations
    $merged = array(
        'email'      => $c['email']      ?? $username,
        'expiryTime' => $expiryTime,
        'enable'     => $c['enable']     ?? true,
        'total'      => $total,
        'up'         => $up,
        'down'       => $down,
        'subId'      => $subId,
        'subIdKnown' => $subIdKnown,
        'lastOnline' => 0,
        'inboundId'  => isset($c['inboundIds'][0]) ? intval($c['inboundIds'][0]) : 0,
        'tgId'       => $c['tgId']       ?? 0,
        'limitIp'    => $c['limitIp']    ?? 0,
    );

    return array(
        'status' => 200,
        'body'   => json_encode(array('success' => true, 'obj' => $merged)),
        'error'  => '',
    );
}

function xui_invoice_for_location($username, $namepanel)
{
    $invoices = select('invoice', '*', 'username', $username, 'fetchAll');
    foreach (($invoices ?: []) as $invoice) {
        if (($invoice['Service_location'] ?? '') === $namepanel) {
            return $invoice;
        }
    }
    return false;
}

function xui_resolve_subscription_id($namepanel, $username, array $client)
{
    if (is_string($client['subId'] ?? null) && $client['subId'] !== '') {
        return ['subId' => $client['subId']];
    }
    // Reading service details must never rotate IDs or modify client settings.
    return ['subId' => '', 'error' => 'Panel did not return a subscription ID'];
}

function addClient($namepanel, $usernameac, $Expire, $Total, $Uuid, $Flow, $subid, $inboundid, $name_product, $note = "", $preserveExpiry = false)
{
    if (!isset($usernameac)) {
        return array('status' => 500, 'msg' => 'username is null');
    }

    $panel = select("marzban_panel", "*", "name_panel", $namepanel, "select");

    // Determine expiry in milliseconds (on_hold = negative value)
    if ($name_product == "usertest") {
        $use_onhold = ($panel['on_hold_test'] == "1");
    } else {
        $use_onhold = ($panel['conecton'] == "onconecton");
    }
    if ($preserveExpiry) {
        $use_onhold = false;
    }

    if ($use_onhold) {
        if ($Expire == 0) {
            $timeservice = 0;
        } else {
            $timelast    = $Expire - time();
            $timeservice = -intval(($timelast / 86400) * 86400000);
        }
    } else {
        $timeservice = $Expire * 1000;
    }

    // Accept single int, "3", or comma-separated "3,5,7"
    if (is_string($inboundid) && strpos($inboundid, ',') !== false) {
        $inboundIds = array_map('intval', explode(',', $inboundid));
    } else {
        $inboundIds = [intval($inboundid)];
    }

    $payload = array(
        "client" => array(
            "email"      => $usernameac,
            "totalGB"    => intval($Total),
            "expiryTime" => $timeservice,
            "tgId"       => 0,
            "limitIp"    => 0,
            "enable"     => true,
            "subId"      => $subid,
            "comment"    => $note,
        ),
        "inboundIds" => $inboundIds,
    );

    $url = $panel['url_panel'] . '/panel/api/clients/add';
    $req = new CurlRequest($url);
    $req->setHeaders(bearer_headers_xui($namepanel));
    return $req->post(json_encode($payload));
}

// $payload is a flat array: email, totalGB, expiryTime, enable, subId, tgId, limitIp
function updateClient($namepanel, $email, array $payload)
{
    $panel = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $url   = $panel['url_panel'] . '/panel/api/clients/update/' . urlencode($email);
    $req   = new CurlRequest($url);
    $req->setHeaders(bearer_headers_xui($namepanel));
    return $req->post(json_encode($payload));
}

function ResetUserDataUsagex_uisin($usernamepanel, $namepanel)
{
    $panel = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $url   = $panel['url_panel'] . "/panel/api/clients/resetTraffic/" . urlencode($usernamepanel);
    $req   = new CurlRequest($url);
    $req->setHeaders(bearer_headers_xui($namepanel));
    return $req->post('{}');
}

function removeClient($location, $username)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $url   = $panel['url_panel'] . "/panel/api/clients/del/" . urlencode($username) . "?keepTraffic=0";
    $req   = new CurlRequest($url);
    $req->setHeaders(bearer_headers_xui($location));
    return $req->post('{}');
}

function get_client_sublinks($namepanel, $subId)
{
    $panel   = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $headers = bearer_headers_xui($namepanel);
    $url     = $panel['url_panel'] . "/panel/api/clients/subLinks/" . urlencode($subId);
    $req     = new CurlRequest($url);
    $req->setHeaders($headers);
    $resp    = $req->get();
    if (empty($resp['error']) && $resp['status'] == 200) {
        $data = json_decode($resp['body'], true);
        if (!empty($data['success']) && !empty($data['obj']) && is_array($data['obj'])) {
            return array_values(array_filter($data['obj']));
        }
    }
    // Older/restored panels may not expose subLinks. The configured public
    // subscription is the fallback; never send panel authorization to it.
    $publicUrl = rtrim($panel['linksubx'] ?? '', '/') . '/' . rawurlencode($subId);
    if (!filter_var($publicUrl, FILTER_VALIDATE_URL)) {
        return [];
    }
    $publicRequest = new CurlRequest($publicUrl);
    $publicResponse = $publicRequest->get();
    if (!empty($publicResponse['error']) || ($publicResponse['status'] ?? 0) != 200) {
        return [];
    }
    $content = trim($publicResponse['body'] ?? '');
    if (!preg_match('/^(vless|vmess|trojan|ss):\/\//', $content)) {
        $decoded = base64_decode($content, true);
        $content = $decoded === false ? '' : trim($decoded);
    }
    $links = preg_split('/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($links, function ($link) {
        return preg_match('/^(vless|vmess|trojan|ss):\/\/\S+$/', $link) === 1;
    }));
}
