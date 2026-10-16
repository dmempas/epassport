<?php

/**
 * Login attempt throttle keyed only by student number (not IP).
 * Used on login.php only — not on other page loads.
 */
function epassport_ensure_login_throttle_table(PDO $conn): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $conn->exec("
        CREATE TABLE IF NOT EXISTS login_throttle (
            student_no VARCHAR(50) NOT NULL PRIMARY KEY,
            fail_count INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            last_fail_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function epassport_normalize_login_student_no(string $studentNo): string
{
    $studentNo = trim($studentNo);

    if ($studentNo === '') {
        return '';
    }

    if (strlen($studentNo) > 50) {
        $studentNo = substr($studentNo, 0, 50);
    }

    return $studentNo;
}

function epassport_login_locked_message(): string
{
    return 'This account has been locked because of too many failed sign-in attempts. Try again in 15 minutes.';
}

/**
 * @return array{locked: bool, message: string}
 */
function epassport_login_throttle_status(PDO $conn, string $studentNo): array
{
    $studentNo = epassport_normalize_login_student_no($studentNo);

    if ($studentNo === '') {
        return ['locked' => false, 'message' => ''];
    }

    epassport_ensure_login_throttle_table($conn);

    $stmt = $conn->prepare("
        SELECT fail_count, locked_until
        FROM login_throttle
        WHERE student_no = ?
        LIMIT 1
    ");
    $stmt->execute([$studentNo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['locked' => false, 'message' => ''];
    }

    $until = (string) ($row['locked_until'] ?? '');

    if ($until !== '' && $until > appNow('Y-m-d H:i:s', $conn)) {
        return [
            'locked' => true,
            'message' => epassport_login_locked_message(),
        ];
    }

    return ['locked' => false, 'message' => ''];
}

function epassport_login_throttle_fail(PDO $conn, string $studentNo): array
{
    $studentNo = epassport_normalize_login_student_no($studentNo);

    if ($studentNo === '') {
        return ['locked' => false, 'fail_count' => 0, 'message' => ''];
    }

    epassport_ensure_login_throttle_table($conn);

    $now = appNow('Y-m-d H:i:s', $conn);

    $read = $conn->prepare("
        SELECT fail_count, locked_until
        FROM login_throttle
        WHERE student_no = ?
        LIMIT 1
    ");
    $read->execute([$studentNo]);
    $row = $read->fetch(PDO::FETCH_ASSOC);

    $count = 1;

    if ($row) {
        $until = (string) ($row['locked_until'] ?? '');

        if ($until !== '' && $until > $now) {
            return [
                'locked' => true,
                'fail_count' => (int) ($row['fail_count'] ?? 0),
                'message' => epassport_login_locked_message(),
            ];
        }

        $count = ($until !== '' && $until <= $now)
            ? 1
            : ((int) $row['fail_count'] + 1);
    }

    $lockUntil = $count >= 5
        ? (new DateTime($now))->modify('+15 minutes')->format('Y-m-d H:i:s')
        : null;

    $upsert = $conn->prepare("
        INSERT INTO login_throttle (student_no, fail_count, locked_until, last_fail_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            fail_count = VALUES(fail_count),
            locked_until = VALUES(locked_until),
            last_fail_at = VALUES(last_fail_at)
    ");
    $upsert->execute([$studentNo, $count, $lockUntil, $now]);

    if ($count >= 3 && $count < 5) {
        sleep(2);
    }

    $locked = $lockUntil !== null;

    return [
        'locked' => $locked,
        'fail_count' => $count,
        'message' => $locked ? epassport_login_locked_message() : '',
    ];
}

function epassport_login_throttle_clear(PDO $conn, string $studentNo): void
{
    $studentNo = epassport_normalize_login_student_no($studentNo);

    if ($studentNo === '') {
        return;
    }

    try {
        $stmt = $conn->prepare("DELETE FROM login_throttle WHERE student_no = ?");
        $stmt->execute([$studentNo]);
    } catch (PDOException $e) {
        // Table may not exist yet.
    }
}
