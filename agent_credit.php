<?php
// Admin navigation only. Wallet/cap changes use the existing audited handlers.
function agentCreditLabels(array $language): array
{
    return array_replace([
        'menu' => '💳 Agent credit management', 'list' => 'Select an agent:',
        'empty' => 'No agents found.', 'search' => '🔎 Search by numeric ID',
        'searchPrompt' => 'Send the agent\'s numeric ID.',
        'notFound' => 'Agent not found. Select a current agent from the list.',
        'invalidId' => 'Send a valid positive numeric ID.', 'back' => 'Back',
        'next' => 'Next', 'previous' => 'Previous', 'refresh' => 'Refresh',
        'currency' => 'toman', 'unlimited' => 'Unlimited',
        'prepaid' => 'Prepaid only (normal agent)',
        'details' => "ID: <code>%s</code>\nUsername: <code>%s</code>\nType: %s\nWallet balance: %s\nCurrent debt: %s\nDebt ceiling: %s\nCurrent purchase capacity: %s",
        'notice' => 'For advanced agents, a debt ceiling of 0 means unlimited credit. Changing the ceiling does not change the wallet balance or existing debt.',
    ], $language['agentCredit'] ?? []);
}

function agentCreditMoney($amount, array $labels): string
{
    return number_format((float) $amount, 0) . ' ' . $labels['currency'];
}

function agentCreditList(PDO $pdo, int $page, array $language): array
{
    $labels = agentCreditLabels($language);
    $total = (int) $pdo->query("SELECT COUNT(*) FROM user WHERE agent IN ('n','n2')")->fetchColumn();
    $pageSize = 8;
    $lastPage = max(0, (int) ceil($total / $pageSize) - 1);
    $page = max(0, min($page, $lastPage));
    $offset = $page * $pageSize;
    $rows = $pdo->query("SELECT id,username,agent,Balance FROM user WHERE agent IN ('n','n2') ORDER BY id LIMIT $pageSize OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
    $keyboard = ['inline_keyboard' => []];
    foreach ($rows as $row) {
        $name = trim((string) ($row['username'] ?? '')) ?: (string) $row['id'];
        $role = $row['agent'] === 'n2' ? 'N2' : 'N';
        $keyboard['inline_keyboard'][] = [[
            'text' => $name . ' | ' . $role . ' | ' . agentCreditMoney($row['Balance'], $labels),
            'callback_data' => "agentcredit_view_{$row['id']}_$page",
        ]];
    }
    $navigation = [];
    if ($page > 0) $navigation[] = ['text' => $labels['previous'], 'callback_data' => 'agentcredit_list_' . ($page - 1)];
    if ($page < $lastPage) $navigation[] = ['text' => $labels['next'], 'callback_data' => 'agentcredit_list_' . ($page + 1)];
    if ($navigation) $keyboard['inline_keyboard'][] = $navigation;
    $keyboard['inline_keyboard'][] = [['text' => $labels['search'], 'callback_data' => 'agentcredit_search']];
    $keyboard['inline_keyboard'][] = [['text' => $labels['back'], 'callback_data' => 'agentlistusers']];
    return ['text' => $rows ? $labels['list'] : $labels['empty'], 'keyboard' => $keyboard];
}

function agentCreditView(PDO $pdo, string $id, int $page, array $language): ?array
{
    $stmt = $pdo->prepare("SELECT id,username,agent,Balance,maxbuyagent FROM user WHERE id = :id AND agent IN ('n','n2')");
    $stmt->execute([':id' => $id]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$agent) return null;
    $labels = agentCreditLabels($language);
    $balance = (float) ($agent['Balance'] ?? 0);
    $cap = (float) ($agent['maxbuyagent'] ?? 0);
    $advanced = $agent['agent'] === 'n2';
    $limit = !$advanced ? $labels['prepaid'] : ($cap == 0 ? $labels['unlimited'] : agentCreditMoney($cap, $labels));
    $capacity = $advanced && $cap == 0 ? $labels['unlimited'] : agentCreditMoney(max(0, $balance + ($advanced ? $cap : 0)), $labels);
    $escape = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $role = $language['keyboard'][$advanced ? 'advancedAgent' : 'normalAgent'] ?? ($advanced ? 'N2' : 'N');
    $text = sprintf($labels['details'], $escape($agent['id']), $escape($agent['username'] ?? '-'), $escape($role),
        agentCreditMoney($balance, $labels), agentCreditMoney(max(0, -$balance), $labels), $escape($limit), $escape($capacity));
    if ($advanced) $text .= "\n\n" . $labels['notice'];
    $keyboard = ['inline_keyboard' => [[
        ['text' => $language['Admin']['manageUser']['addBalanceUser'] ?? 'Add balance', 'callback_data' => 'addbalanceuser_' . $agent['id']],
        ['text' => $language['Admin']['manageUser']['lowBalanceUser'] ?? 'Subtract balance', 'callback_data' => 'lowbalanceuser_' . $agent['id']],
    ]]];
    if ($advanced) $keyboard['inline_keyboard'][] = [[
        'text' => $language['keyboard']['agentPurchaseCap'] ?? 'Debt ceiling', 'callback_data' => 'maxbuyagent_' . $agent['id'],
    ]];
    $keyboard['inline_keyboard'][] = [['text' => $labels['refresh'], 'callback_data' => "agentcredit_view_{$agent['id']}_$page"]];
    $keyboard['inline_keyboard'][] = [['text' => $labels['back'], 'callback_data' => "agentcredit_list_$page"]];
    return ['text' => $text, 'keyboard' => $keyboard];
}

function handleAgentCreditManagement(): bool
{
    global $datain, $text, $user, $adminrulecheck, $admin_ids, $from_id, $pdo, $textbotlang, $message_id;
    $callback = (string) ($datain ?? '');
    $isCallback = strpos($callback, 'agentcredit_') === 0;
    $isSearch = ($user['step'] ?? '') === 'agent_credit_search' && $callback === '';
    if (!$isCallback && !$isSearch) return false;
    // Preserve the application's existing Back handlers during numeric search.
    if ($isSearch && in_array($text, array_filter([
        $textbotlang['Admin']['backAdminBtn'] ?? null, $textbotlang['Admin']['backMenuBtn'] ?? null,
    ]), true)) return false;
    if (!in_array($from_id, $admin_ids) || ($adminrulecheck['rule'] ?? '') !== 'administrator') return true;
    $labels = agentCreditLabels($textbotlang);
    if ($callback === 'agentcredit_search') {
        step('agent_credit_search', $from_id);
        sendmessage($from_id, $labels['searchPrompt'], json_encode(['inline_keyboard' => [[
            ['text' => $labels['back'], 'callback_data' => 'agentcredit_list_0'],
        ]]], JSON_UNESCAPED_UNICODE), 'HTML');
        return true;
    }
    if ($isSearch) {
        $id = trim((string) $text);
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            sendmessage($from_id, $labels['invalidId'], null, 'HTML');
            return true;
        }
        $view = agentCreditView($pdo, $id, 0, $textbotlang);
        if (!$view) {
            sendmessage($from_id, $labels['notFound'], null, 'HTML');
            return true;
        }
    } elseif (preg_match('/^agentcredit_list_([0-9]{1,6})$/D', $callback, $matches)) {
        $view = agentCreditList($pdo, (int) $matches[1], $textbotlang);
    } elseif (preg_match('/^agentcredit_view_([1-9][0-9]{0,18})_([0-9]{1,6})$/D', $callback, $matches)) {
        $view = agentCreditView($pdo, $matches[1], (int) $matches[2], $textbotlang);
        if (!$view) {
            sendmessage($from_id, $labels['notFound'], null, 'HTML');
            return true;
        }
    } else {
        return true;
    }
    step('home', $from_id);
    $markup = json_encode($view['keyboard'], JSON_UNESCAPED_UNICODE);
    if ($isCallback) Editmessagetext($from_id, $message_id, $view['text'], $markup);
    else sendmessage($from_id, $view['text'], $markup, 'HTML');
    return true;
}
