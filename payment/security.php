<?php

function acquirePaymentCallbackLock($orderId)
{
    $key = hash('sha256', (string) $orderId);
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mirzabot-payment-' . $key . '.lock';
    $handle = fopen($path, 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Unable to acquire payment callback lock.');
    }
    return $handle;
}
