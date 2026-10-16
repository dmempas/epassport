<?php



require_once __DIR__ . '/realtime_config.php';

/**
 * Push a location request to the student's connected device(s).

 * Uses WebSocket server when running; always queues a DB event as fallback.

 *

 * @return array{queued: bool, clients_notified: int, notify_ok: bool}

 */

function realtime_notify_location_request(

    PDO $conn,

    int $userId,

    int $attendanceId

): array {



    $result = [

        'queued' => false,

        'clients_notified' => 0,

        'notify_ok' => false,

    ];



    $payload = [

        'type' => 'location_request',

        'attendance_id' => $attendanceId,

        'user_id' => $userId,

        'timestamp' => time(),

    ];



    $payloadJson = json_encode($payload);



    try {

        $stmt = $conn->prepare("

            INSERT INTO realtime_events (user_id, event_type, payload)

            VALUES (?, 'location_request', ?)

        ");

        $stmt->execute([$userId, $payloadJson]);

        $result['queued'] = true;

    } catch (PDOException $e) {

        error_log('realtime_events insert failed: ' . $e->getMessage());

    }



    $config = epassport_realtime_config();



    if (empty($config['enabled']) || empty($config['notify_url'])) {

        return $result;

    }



    if (!function_exists('curl_init')) {

        return $result;

    }



    $body = json_encode([

        'user_id' => $userId,

        'payload' => $payload,

    ]);



    $ch = curl_init($config['notify_url']);



    curl_setopt_array($ch, [

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => $body,

        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_TIMEOUT => 3,

        CURLOPT_CONNECTTIMEOUT => 2,

    ]);



    $response = curl_exec($ch);

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $curlError = curl_error($ch);

    curl_close($ch);



    if ($curlError !== '') {

        error_log('WebSocket notify curl error: ' . $curlError);



        return $result;

    }



    if ($httpCode >= 200 && $httpCode < 300 && is_string($response)) {

        $decoded = json_decode($response, true);



        if (is_array($decoded)) {

            $result['notify_ok'] = !empty($decoded['success']);

            $result['clients_notified'] = (int) ($decoded['clients_notified'] ?? 0);

        }

    }



    return $result;

}

/**
 * Tell the student they may turn off location after a logout stamp.
 *
 * @return array{queued: bool, clients_notified: int, notify_ok: bool}
 */
function realtime_notify_logout_reminder(
    PDO $conn,
    int $userId,
    int $attendanceId,
    string $scanType,
    string $eventName
): array {
    $result = [
        'queued' => false,
        'clients_notified' => 0,
        'notify_ok' => false,
    ];

    $payload = [
        'type' => 'logout_reminder',
        'attendance_id' => $attendanceId,
        'user_id' => $userId,
        'scan_type' => $scanType,
        'event_name' => $eventName,
        'timestamp' => time(),
    ];

    $payloadJson = json_encode($payload);

    try {
        $stmt = $conn->prepare("
            INSERT INTO realtime_events (user_id, event_type, payload)
            VALUES (?, 'logout_reminder', ?)
        ");
        $stmt->execute([$userId, $payloadJson]);
        $result['queued'] = true;
    } catch (PDOException $e) {
        error_log('realtime_events logout insert failed: ' . $e->getMessage());
    }

    $config = epassport_realtime_config();

    if (empty($config['enabled']) || empty($config['notify_url'])) {
        return $result;
    }

    if (!function_exists('curl_init')) {
        return $result;
    }

    $body = json_encode([
        'user_id' => $userId,
        'payload' => $payload,
    ]);

    $ch = curl_init($config['notify_url']);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        error_log('WebSocket logout notify curl error: ' . $curlError);

        return $result;
    }

    if ($httpCode >= 200 && $httpCode < 300 && is_string($response)) {
        $decoded = json_decode($response, true);

        if (is_array($decoded)) {
            $result['notify_ok'] = !empty($decoded['success']);
            $result['clients_notified'] = (int) ($decoded['clients_notified'] ?? 0);
        }
    }

    return $result;
}

/**
 * Fetch the latest undelivered logout reminder for a student.
 */
function fetchPendingLogoutReminder(PDO $conn, int $userId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT event_id, payload
            FROM realtime_events
            WHERE user_id = ?
              AND event_type = 'logout_reminder'
              AND delivered_at IS NULL
            ORDER BY event_id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);

        if (!is_array($payload)) {
            return null;
        }

        $payload['realtime_event_id'] = (int) $row['event_id'];

        return $payload;
    } catch (PDOException $e) {
        return null;
    }
}


