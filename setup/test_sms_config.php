<?php

/**
 * CLI smoke test for config/sms.php — does not send SMS unless --send=NUMBER is passed.
 * Usage: php setup/test_sms_config.php
 *        php setup/test_sms_config.php --send=09171234567
 */

require_once __DIR__ . '/../config/sms.php';

$sendTo = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--send=')) {
        $sendTo = substr($arg, 7);
    }
}

echo "=== SMS configuration ===\n";
echo 'Configured: ' . (SMS::isConfigured() ? 'yes' : 'no') . "\n";
echo 'cURL: ' . (function_exists('curl_init') ? 'enabled' : 'missing') . "\n";

echo "\n=== Credits check ===\n";
$credits = SMS::checkCredits();
echo 'OK: ' . ($credits['ok'] ? 'yes' : 'no') . "\n";
echo 'Message: ' . $credits['message'] . "\n";
echo 'Credits: ' . ($credits['credits'] === null ? 'n/a' : (string) $credits['credits']) . "\n";

echo "\n=== wasSendSuccessful() ===\n";
$samples = [
    'IProg queue 200' => '{"status":200,"message":"Your SMS message has been successfully added to the queue.","message_id":"iSms-test"}',
    'IProg credits' => '{"status":"success","message":"Account found.","data":{"load_balance":5}}',
    'Invalid token' => '{"status":500,"message":"Invalid Token"}',
    'Local error' => '{"status":"error","message":"Invalid phone number."}',
    'Local pending' => '{"status":"pending","message":"IProg SMS API token not configured yet."}',
];

foreach ($samples as $label => $json) {
    echo $label . ': ' . (SMS::wasSendSuccessful($json) ? 'success' : 'fail') . "\n";
}

echo "\n=== canSend() ===\n";
$canSend = SMS::canSend();
echo 'OK: ' . ($canSend['ok'] ? 'yes' : 'no') . "\n";
echo 'Message: ' . $canSend['message'] . "\n";
echo 'Credits: ' . ($canSend['credits'] === null ? 'n/a' : (string) $canSend['credits']) . "\n";

echo "\n=== Phone validation (no API call) ===\n";
$phones = ['', 'abc', '09171234567', '639171234567', '+63 917 123 4567', '9171234567', '6391712345678'];
foreach ($phones as $phone) {
    $resp = SMS::send($phone, 'E-PASSPORT phone validation test');
    $parsed = SMS::parseResponse($resp);
    echo $phone . ' => ' . $parsed['message'] . "\n";
}

echo "\n=== Template samples ===\n";
echo "--- 30 min outside ---\n";
echo SMS::outsideWarningMessage('Juan', 'Dela Cruz') . "\n";
echo "--- 15 min no signal ---\n";
echo SMS::noSignalWarningMessage('Juan', 'Dela Cruz') . "\n";
echo "--- 30 min no signal ---\n";
echo SMS::noSignalViolationMessage('Juan', 'Dela Cruz') . "\n";

if ($sendTo !== null && $sendTo !== '') {
    echo "\n=== Live send test ===\n";
    $message = 'E-PASSPORT SMS test at ' . date('Y-m-d H:i:s') . ' — ignore if unexpected.';
    $response = SMS::send($sendTo, $message);
    echo "Response: {$response}\n";
    echo 'Parsed success: ' . (SMS::wasSendSuccessful($response) ? 'yes' : 'no') . "\n";
} else {
    echo "\n(Skipping live send — pass --send=09XXXXXXXXX to test delivery.)\n";
}
