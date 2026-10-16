<?php

/**
 * IProg SMS integration for E-PASSPORT.
 * Header/footer are configured in the IProg dashboard:
 *   Header: USTP Panaon - E-Passport
 *   Footer: - Supreme Student Council
 * Token: EPASSPORT_SMS_API_TOKEN in the project .env file (not in the browser).
 */

if (!function_exists('epassport_env')) {
    require_once __DIR__ . '/../includes/env.php';
}

class SMS
{
    private static $endpoint = 'https://www.iprogsms.com/api/v1/sms_messages';

    private static $creditsEndpoint = 'https://www.iprogsms.com/api/v1/account/sms_credits';

    /** @var array<string, string> */
    private static $templates = [
        'outside_warning' =>
            "Good day. Our records show that you have been outside the event venue for more than 30 minutes. "
            . "The Supreme Student Council is aware of this. "
            . "Please report back to the SSC Office or directly to the SSC President before logging out to void the warning. "
            . "Otherwise, you will be marked absent and may incur penalties.\n\n"
            . "Thank You!",

        'no_signal_warning' =>
            "Good day. We have not received your location updates for over 15 minutes. "
            . "Please ensure that your Location Services and internet connection (WiFi/mobile data) are turned on "
            . "so the system can continuously verify your presence inside the event venue.\n\n"
            . "This is very important for your attendance record. Failure to restore tracking may affect your attendance status.",

        'no_signal_violation' =>
            "Good day. Our records show that your device has not transmitted any location updates for more than 30 minutes. "
            . "As a result, you have incurred a penalty for prolonged loss of signal during the event.\n\n"
            . "To void this penalty, it is strongly advised that you report to the SSC Office before logging out "
            . "so your attendance status can be reviewed and verified. Please ensure that your Location Services "
            . "and internet connection (WiFi or mobile data) remain enabled throughout the event to maintain continuous attendance tracking.",

        'location_off_violation' =>
            'Hi :name, USTP Panaon E-Passport: Your location services appear to be OFF during an active attendance session. '
            . 'This is recorded as a violation. Please turn on GPS and contact the SSC Office.',

        'penalty_notice' =>
            'Hi :name, USTP Panaon E-Passport: A penalty was recorded for :event — :reason. '
            . 'Log in to your student portal to view amounts and choose monetary payment or community service.',

        'attendance_present' =>
            'Hi :name, USTP Panaon E-Passport: Your attendance for :scan_type at :event was marked PRESENT. '
            . 'Keep location services ON for continuous monitoring during the event.',
    ];

    public static function isConfigured(): bool
    {
        return self::apiToken() !== '';
    }

    /**
     * @return array{ok: bool, message: string, credits: float|null}
     */
    public static function canSend(): array
    {
        if (!self::isConfigured()) {
            return [
                'ok' => false,
                'message' => 'IProg SMS API token is not configured (EPASSPORT_SMS_API_TOKEN in .env).',
                'credits' => null,
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'message' => 'PHP cURL extension is not enabled.',
                'credits' => null,
            ];
        }

        $credits = self::checkCredits();

        if (!$credits['ok']) {
            return [
                'ok' => false,
                'message' => $credits['message'],
                'credits' => null,
            ];
        }

        if ($credits['credits'] !== null && $credits['credits'] <= 0) {
            return [
                'ok' => false,
                'message' => 'IProg SMS account has no credits. Top up load balance in the IProg dashboard.',
                'credits' => 0.0,
            ];
        }

        return [
            'ok' => true,
            'message' => $credits['message'],
            'credits' => $credits['credits'],
        ];
    }

    public static function send($number, $message)
    {
        if (!self::isConfigured()) {
            return json_encode([
                'status' => 'error',
                'message' => 'IProg SMS API token is not configured (EPASSPORT_SMS_API_TOKEN in .env).',
            ]);
        }

        if (!function_exists('curl_init')) {
            return json_encode([
                'status' => 'error',
                'message' => 'PHP cURL extension is not enabled. Enable curl in php.ini.',
            ]);
        }

        $phone = self::normalizePhoneNumber((string) $number);

        if ($phone === '') {
            return json_encode(['status' => 'error', 'message' => 'Invalid phone number. Use 09XXXXXXXXX or 639XXXXXXXXX.']);
        }

        $message = trim((string) $message);

        if ($message === '') {
            return json_encode(['status' => 'error', 'message' => 'SMS message is empty.']);
        }

        $preflight = self::canSend();

        if (!$preflight['ok']) {
            return json_encode([
                'status' => 'error',
                'message' => $preflight['message'],
                'credits' => $preflight['credits'],
            ]);
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, self::$endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'api_token' => self::apiToken(),
            'phone_number' => $phone,
            'message' => $message,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return json_encode(['status' => 'error', 'message' => $curlError ?: 'SMS request failed.']);
        }

        return $response;
    }

    /**
     * @return array{success: bool, status: string, message: string, message_id: string|null, raw: string}
     */
    public static function parseResponse($response): array
    {
        if (!is_string($response) || trim($response) === '') {
            return [
                'success' => false,
                'status' => 'error',
                'message' => 'Empty SMS API response.',
                'message_id' => null,
                'raw' => (string) $response,
            ];
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => trim($response),
                'message_id' => null,
                'raw' => $response,
            ];
        }

        return [
            'success' => self::wasSendSuccessful($response),
            'status' => (string) ($decoded['status'] ?? ''),
            'message' => (string) ($decoded['message'] ?? ''),
            'message_id' => isset($decoded['message_id']) ? (string) $decoded['message_id'] : null,
            'raw' => $response,
        ];
    }

    /**
     * @return array{ok: bool, message: string, credits: float|null, raw: string|false}
     */
    public static function checkCredits(): array
    {
        if (!self::isConfigured()) {
            return [
                'ok' => false,
                'message' => 'IProg SMS API token is not configured.',
                'credits' => null,
                'raw' => false,
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'message' => 'PHP cURL extension is not enabled.',
                'credits' => null,
                'raw' => false,
            ];
        }

        $url = self::$creditsEndpoint . '?' . http_build_query(['api_token' => self::apiToken()]);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'ok' => false,
                'message' => $curlError ?: 'Could not reach IProg SMS API.',
                'credits' => null,
                'raw' => false,
            ];
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Unexpected response from IProg SMS credits API.',
                'credits' => null,
                'raw' => $response,
            ];
        }

        if (!self::wasSendSuccessful($response)) {
            return [
                'ok' => false,
                'message' => (string) ($decoded['message'] ?? 'IProg SMS credits check failed.'),
                'credits' => null,
                'raw' => $response,
            ];
        }

        $credits = isset($decoded['data']['load_balance'])
            ? (float) $decoded['data']['load_balance']
            : null;

        return [
            'ok' => true,
            'message' => (string) ($decoded['message'] ?? 'Account found.'),
            'credits' => $credits,
            'raw' => $response,
        ];
    }

    public static function wasSendSuccessful($response): bool
    {
        if (!is_string($response) || trim($response) === '') {
            return false;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            return stripos($response, 'error') === false;
        }

        if (isset($decoded['status'])) {
            $rawStatus = $decoded['status'];

            if (is_numeric($rawStatus)) {
                $code = (int) $rawStatus;

                if ($code >= 200 && $code < 300) {
                    return true;
                }

                if ($code >= 400) {
                    return false;
                }
            }

            $status = strtolower((string) $rawStatus);

            if (in_array($status, ['success', 'sent', 'ok', 'queued'], true)) {
                return true;
            }

            if (in_array($status, ['error', 'failed', 'fail', 'pending'], true)) {
                return false;
            }
        }

        $message = strtolower((string) ($decoded['message'] ?? ''));

        if ($message !== '' && (
            str_contains($message, 'invalid token')
            || str_contains($message, 'failed')
            || str_contains($message, 'error')
        )) {
            return false;
        }

        if ($message !== '' && (
            str_contains($message, 'successfully')
            || str_contains($message, 'queued')
            || str_contains($message, 'account found')
        )) {
            return true;
        }

        return empty($decoded['error']) && empty($decoded['errors']);
    }

    public static function build(string $templateKey, array $vars = []): string
    {
        $template = self::$templates[$templateKey] ?? '';

        foreach ($vars as $key => $value) {
            $template = str_replace(':' . $key, (string) $value, $template);
        }

        return trim($template);
    }

    public static function sendTemplate(string $number, string $templateKey, array $vars = [])
    {
        return self::send($number, self::build($templateKey, $vars));
    }

    public static function outsideWarningMessage(string $firstName, string $lastName): string
    {
        return self::build('outside_warning', [
            'name' => trim($firstName . ' ' . $lastName),
        ]);
    }

    public static function noSignalWarningMessage(string $firstName, string $lastName): string
    {
        return self::build('no_signal_warning', [
            'name' => trim($firstName . ' ' . $lastName),
        ]);
    }

    public static function noSignalViolationMessage(string $firstName, string $lastName): string
    {
        return self::build('no_signal_violation', [
            'name' => trim($firstName . ' ' . $lastName),
        ]);
    }

    public static function locationOffViolationMessage(string $firstName, string $lastName): string
    {
        return self::build('location_off_violation', [
            'name' => trim($firstName . ' ' . $lastName),
        ]);
    }

    public static function penaltyNoticeMessage(
        string $firstName,
        string $lastName,
        string $eventName,
        string $reason
    ): string {
        return self::build('penalty_notice', [
            'name' => trim($firstName . ' ' . $lastName),
            'event' => $eventName,
            'reason' => $reason,
        ]);
    }

    private static function apiToken(): string
    {
        return trim(epassport_env('EPASSPORT_SMS_API_TOKEN'));
    }

    private static function normalizePhoneNumber(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return $digits;
        }

        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            return '63' . substr($digits, 1);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '63' . $digits;
        }

        return '';
    }
}
