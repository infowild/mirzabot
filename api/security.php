<?php

if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strncmp($name, 'HTTP_', 5) !== 0) {
                continue;
            }
            $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$key] = $value;
        }
        return $headers;
    }
}

function apiHeaderValue(array $headers, string $name): ?string
{
    foreach ($headers as $key => $value) {
        if (strcasecmp((string) $key, $name) === 0) {
            return trim((string) $value);
        }
    }
    return null;
}

function apiValidateAdminToken(array $headers): bool
{
    global $ADMIN_API_TOKEN;
    $candidate = apiHeaderValue($headers, 'Token');
    if ($candidate === null || $candidate === '') {
        return false;
    }
    $valid = [];
    if (is_string($ADMIN_API_TOKEN ?? null) && $ADMIN_API_TOKEN !== '' && $ADMIN_API_TOKEN !== '{ADMIN_API_TOKEN}') {
        $valid[] = $ADMIN_API_TOKEN;
    }
    $legacyPath = __DIR__ . '/hash.txt';
    if (is_file($legacyPath)) {
        $legacy = trim((string) file_get_contents($legacyPath));
        if ($legacy !== '') {
            $valid[] = $legacy;
        }
    }
    foreach ($valid as $token) {
        if (hash_equals($token, $candidate)) {
            return true;
        }
    }
    return false;
}

function apiRedact($value, string $key = '')
{
    if (preg_match('/(?:token|authorization|cookie|api[-_]?key|password|secret)/i', $key)) {
        return '[REDACTED]';
    }
    if (is_array($value)) {
        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = apiRedact($childValue, (string) $childKey);
        }
    }
    return $value;
}

function apiLogRequest(PDO $pdo, array $headers, array $data, string $action): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO logs_api (header,data,time,ip,actions) VALUES (?,?,?,?,?)');
    $stmt->execute([
        json_encode(apiRedact($headers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode(apiRedact($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        date('Y/m/d H:i:s'),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $action,
    ]);
}
