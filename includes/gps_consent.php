<?php

const EPASSPORT_GPS_CONSENT_BUTTON =
    'I Give Full Consent to the Collection of My GPS Location Data';

const EPASSPORT_GPS_CONSENT_TEXT = <<<'TEXT'
GPS Location Consent Notice

GPS Location Access Consent

The E-PASSPORT: USTP Panaon Campus Student Monitoring System requires access to your device's location services to support attendance validation, campus-restricted monitoring, and compliance with university event participation policies.

By logging in to and using this system, you acknowledge and consent to the collection and processing of your real-time GPS location data during authorized university activities and events. Your location data is collected solely for the following purposes:

• To verify your presence within the designated USTP Panaon Campus or official event venue geofence during authorized events and activities.
• To validate attendance records and prevent unauthorized or proxy attendance.
• To support real-time campus monitoring and ensure compliance with institutional policies.
• To enable automated notifications, warnings, and penalty processing when monitoring rules are violated.
• To maintain accurate attendance, monitoring, and audit records for administrative and security purposes.

The E-PASSPORT system uses location data only in connection with official university monitoring activities and does not collect location information for purposes unrelated to attendance validation, campus safety, or policy enforcement. Location data is handled in accordance with applicable data privacy regulations and university policies and is accessible only to authorized personnel for legitimate administrative purposes.

If location access is denied, certain system features, including attendance validation and campus monitoring services, may not function properly, and attendance verification may be affected.

By selecting "I Give Full Consent to the Collection of My GPS Location Data" and continuing to use the E-PASSPORT system, you confirm that you have read, understood, and agreed to this GPS Location Access Consent Notice.
TEXT;

function epassport_ensure_gps_consent_table(PDO $conn): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $conn->exec("
        CREATE TABLE IF NOT EXISTS gps_consents (
            consent_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            agreed_at DATETIME NOT NULL,
            consent_text TEXT NOT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            KEY idx_gps_consents_user_agreed (user_id, agreed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function epassport_record_gps_consent(PDO $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    epassport_ensure_gps_consent_table($conn);

    $agreedAt = appNow('Y-m-d H:i:s', $conn);

    $stmt = $conn->prepare("
        INSERT INTO gps_consents (user_id, agreed_at, consent_text, ip_address)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $agreedAt,
        EPASSPORT_GPS_CONSENT_TEXT,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    require_once __DIR__ . '/audit_helpers.php';
    logAudit(
        $conn,
        'gps_consent_given',
        'gps_consents',
        $userId,
        'Gave full consent to GPS location collection for attendance and campus monitoring.',
        $userId
    );

    $_SESSION['student_gps_consent_ok'] = 1;
}

function epassport_student_gps_consent_ok(): bool
{
    return !empty($_SESSION['student_gps_consent_ok']);
}

function epassport_gps_consent_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (preg_match('#/(admin|student|officer|ajax)/#', $script)) {
        return '../student/gps_consent.php';
    }

    return 'student/gps_consent.php';
}

function epassport_require_student_gps_consent(): void
{
    if ((int) ($_SESSION['role_id'] ?? 0) !== 2) {
        return;
    }

    if (epassport_student_gps_consent_ok()) {
        return;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (preg_match('#/ajax/#', $script)) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Location consent is required before using the student portal.',
        ]);
        exit;
    }

    if (!headers_sent()) {
        header('Location: ' . epassport_gps_consent_url());
    }
    exit;
}
