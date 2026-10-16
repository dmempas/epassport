<?php



require_once '../includes/session.php';

requireRole(3);



require_once '../config/database.php';

require_once '../includes/event_helpers.php';
require_once '../includes/attendance_schema.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/monitoring_helpers.php';
require_once '../includes/penalty_helpers.php';



header('Content-Type: application/json');



$db = new Database();

$conn = $db->connect();

ensureAttendanceSchema($conn);



try {



    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        throw new Exception("Invalid request method");

    }



    $rawQr = readPostedQrData();



    if (!is_string($rawQr) || trim($rawQr) === '') {

        throw new Exception("QR data missing");

    }



    $student = resolveStudentFromQrPayload($conn, $rawQr);



    if (!$student) {

        throw new Exception("Invalid QR format or student not found");

    }



    $officerEventId = isset($_SESSION['officer_selected_event_id'])

        ? (int) $_SESSION['officer_selected_event_id']

        : 0;



    $wantLateScan = isset($_POST['late_scan']) && (string) $_POST['late_scan'] === '1';

    $activeEvent = getActiveEvent($conn);

    if (!$activeEvent) {
        throw new Exception('There is no active event. Attendance scanning is disabled until an administrator activates a fully configured event.');
    }

    if ($officerEventId > 0 && $officerEventId !== (int) $activeEvent['event_id']) {
        throw new Exception('Your selected event does not match the system active event. Open the officer dashboard and select the active event.');
    }

    $event = $activeEvent;
    $event_id = (int) $event['event_id'];
    $event_name = $event['event_name'];
    $isLateScan = false;
    $scanRule = null;

    if ($wantLateScan) {
        $lateAvail = getLateScanAvailability($conn, $event);

        if (empty($lateAvail['allowed']) || empty($lateAvail['scan_rule'])) {
            throw new Exception($lateAvail['reason'] ?? 'Late scanning is not available right now.');
        }

        $scanRule = $lateAvail['scan_rule'];
        $isLateScan = true;
    } else {
        $resolved = resolveOfficerScanEvent($conn, $officerEventId);
        $scanRule = $resolved['availability']['scan_rule'] ?? null;

        if (!$resolved['event'] && empty($scanRule)) {
            throw new Exception($resolved['error'] ?? 'No scannable event available.');
        }

        if (!$scanRule) {
            $lateAvail = getLateScanAvailability($conn, $event);

            if (!empty($lateAvail['allowed']) && !empty($lateAvail['scan_rule'])) {
                throw new Exception(
                    'The regular scan window is closed. Use Scan Late Students to record this stamp.'
                );
            }

            throw new Exception(
                $resolved['error']
                ?? 'No scan window is open at this time.'
            );
        }
    }



    // One stamp per student / scan type / day. Login QR may be scanned again to
    // re-send a location request. Present/late is final for this stamp.
    // Auto-absent (never scanned by an officer) is also final.

    $conn->beginTransaction();

    try {
    $stmt = $conn->prepare("
        SELECT attendance_id, status, scanned_by_officer_id
        FROM attendance_sessions
        WHERE user_id = ?
        AND event_id = ?
        AND scan_type = ?
        AND DATE(qr_scanned_at) = CURDATE()
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $student['user_id'],
        $event_id,
        $scanRule['scan_type'],
    ]);

    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $attendance_id = null;
    $studentName = trim($student['firstname'] . ' ' . $student['lastname']);
    $windowLabel = formatAppTimeRange($scanRule['start_time'], $scanRule['end_time']);

    $alreadyPresentPayload = [
        'success' => true,
        'already_present' => true,
        'attendance_id' => $existing ? (int) $existing['attendance_id'] : null,
        'user_id' => (int) $student['user_id'],
        'student_no' => $student['student_no'] ?? '',
        'student_name' => $studentName,
        'message' => 'This student is already marked present',
        'scan_type' => $scanRule['scan_type'],
        'scan_window' => $windowLabel,
        'event_name' => $event_name,
        'gps_note' => '',
        'is_rescan' => false,
    ];

    if ($existing) {
        $existingStatus = (string) $existing['status'];
        $attendance_id = (int) $existing['attendance_id'];
        $wasOfficerScanned = $existing['scanned_by_officer_id'] !== null
            && (int) $existing['scanned_by_officer_id'] > 0;

        if (in_array($existingStatus, ['present', 'late'], true)) {
            $conn->rollBack();
            logAudit(
                $conn,
                'officer_scan_duplicate',
                'attendance_sessions',
                $attendance_id,
                $studentName . ' — already marked ' . $existingStatus
                    . ' (' . formatScanTypeLabel($scanRule['scan_type']) . ')'
            );
            echo json_encode($alreadyPresentPayload);
            exit;
        }

        if ($existingStatus === 'absent' && !$wasOfficerScanned && !$isLateScan) {
            $conn->rollBack();
            logAudit(
                $conn,
                'officer_scan_failed',
                'attendance_sessions',
                $attendance_id,
                $studentName . ' — scan rejected: student already marked absent; use late scan'
            );
            echo json_encode([
                'success' => false,
                'user_id' => (int) $student['user_id'],
                'student_no' => $student['student_no'] ?? '',
                'student_name' => $studentName,
                'message' => 'This student was marked absent because they were never scanned. Use Scan Late Students after the window closes.',
            ]);
            exit;
        }

        if (!isLogoutScanType($scanRule['scan_type'])) {
            if (attendancePenaltyHasRecordedPayment($conn, $attendance_id)) {
                $conn->rollBack();
                logAudit(
                    $conn,
                    'officer_scan_failed',
                    'attendance_sessions',
                    $attendance_id,
                    $studentName . ' — scan rejected: penalty already has a recorded payment'
                );
                echo json_encode([
                    'success' => false,
                    'user_id' => (int) $student['user_id'],
                    'student_no' => $student['student_no'] ?? '',
                    'student_name' => $studentName,
                    'message' => 'This stamp already has a penalty with a recorded payment. Another scan cannot remove it.',
                ]);
                exit;
            }

            $stmt = $conn->prepare("
                UPDATE attendance_sessions
                SET status = 'pending',
                    scanned_by_officer_id = ?,
                    qr_scanned_at = NOW(),
                    location_requested_at = NOW(),
                    is_rescan = 0,
                    is_late_scan = ?
                WHERE attendance_id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $isLateScan ? 1 : 0, $attendance_id]);
            clearUnsettledPenaltyForAttendance($conn, $attendance_id);
        }
    }

    if (isLogoutScanType($scanRule['scan_type'])) {

        $logoutStatus = $isLateScan ? 'late' : 'present';

        if ($attendance_id && $isLateScan && attendancePenaltyHasRecordedPayment($conn, (int) $attendance_id)) {
            $conn->rollBack();
            logAudit(
                $conn,
                'officer_scan_failed',
                'attendance_sessions',
                (int) $attendance_id,
                $studentName . ' — late logout rejected: penalty already has a recorded payment'
            );
            echo json_encode([
                'success' => false,
                'user_id' => (int) $student['user_id'],
                'student_no' => $student['student_no'] ?? '',
                'student_name' => $studentName,
                'message' => 'This stamp already has a penalty with a recorded payment, so a late logout cannot replace it.',
            ]);
            exit;
        }

        if (!$attendance_id) {

            $stmt = $conn->prepare("

                INSERT INTO attendance_sessions

                (

                    user_id,

                    event_id,

                    scanned_by_officer_id,

                    scan_type,

                    status,

                    qr_scanned_at,

                    is_late_scan

                )

                VALUES

                (?, ?, ?, ?, ?, NOW(), ?)

            ");

            $stmt->execute([
                $student['user_id'],
                $event_id,
                $_SESSION['user_id'],
                $scanRule['scan_type'],
                $logoutStatus,
                $isLateScan ? 1 : 0,
            ]);

            $attendance_id = (int) $conn->lastInsertId();

        } else {

            $stmt = $conn->prepare("

                UPDATE attendance_sessions

                SET status = ?,
                    scanned_by_officer_id = ?,
                    qr_scanned_at = NOW(),
                    is_rescan = 0,
                    is_late_scan = ?
                WHERE attendance_id = ?

            ");

            $stmt->execute([$logoutStatus, $_SESSION['user_id'], $isLateScan ? 1 : 0, $attendance_id]);

        }



        completeMonitoringForLogout(

            $conn,

            (int) $student['user_id'],

            $event_id

        );



        require_once '../includes/realtime_notify.php';



        $notifyResult = realtime_notify_logout_reminder(

            $conn,

            (int) $student['user_id'],

            (int) $attendance_id,

            $scanRule['scan_type'],

            $event_name

        );



        $windowLabel = formatAppTimeRange($scanRule['start_time'], $scanRule['end_time']);



        $logoutNote = 'Logout recorded. Student may turn off location on their device.';
        $violationNote = '';

        try {
            $pairedLogin = pairedLoginScanType((string) $scanRule['scan_type']);

            if ($pairedLogin) {
                $stmtViolation = $conn->prepare("
                    SELECT a.status
                    FROM attendance_sessions a
                    WHERE a.user_id = ?
                      AND a.event_id = ?
                      AND a.scan_type = ?
                      AND DATE(a.qr_scanned_at) = CURDATE()
                      AND a.status = 'violated'
                    LIMIT 1
                ");
                $stmtViolation->execute([(int) $student['user_id'], $event_id, $pairedLogin]);

                if ($stmtViolation->fetchColumn()) {
                    $violationNote = ' Today\'s '
                        . formatScanTypeLabel($pairedLogin)
                        . ' stamp is still Violated. This logout stays '
                        . $logoutStatus
                        . '. The penalty remains until an administrator (SSC moderator or president) voids it on Live Student Tracking.';
                }
            }
        } catch (PDOException $e) {
            // Non-fatal — logout stamp still stands.
        }

        if ($notifyResult['clients_notified'] > 0) {
            $logoutNote = 'Logout reminder delivered instantly via WebSocket ('
                . $notifyResult['clients_notified'] . ' device(s)).';
        } elseif ($notifyResult['queued']) {
            $logoutNote = 'Logout reminder queued. Student will be notified when their dashboard is open.';
        }

        $logoutNote .= $violationNote;



        logAudit(

            $conn,

            'officer_scan',

            'attendance_sessions',

            (int) $attendance_id,

            trim($student['firstname'] . ' ' . $student['lastname'])

                . ' — '

                . formatScanTypeLabel($scanRule['scan_type'])

                . ($isLateScan ? ' (late logout — monitoring ended)' : ' (logout — monitoring ended)')

        );



        if ($isLateScan) {
            clearUnsettledPenaltyForAttendance($conn, (int) $attendance_id);
            createPenaltyForAttendance(
                $conn,
                (int) $attendance_id,
                (int) $student['user_id'],
                $event_id,
                'Late attendance (scanned after the window closed)',
                'late'
            );
        }

        echo json_encode([

            'success' => true,

            'is_logout' => true,

            'attendance_id' => $attendance_id,

            'user_id' => (int) $student['user_id'],

            'student_no' => $student['student_no'] ?? '',

            'student_name' => trim(

                $student['firstname'] . ' ' . $student['lastname']

            ),

            'message' => ($isLateScan ? 'Late logout stamp recorded for ' : 'Logout stamp recorded for ')

                . $student['firstname'] . ' ' . $student['lastname']

                . ' (' . ($student['student_no'] ?? '') . ')',

            'scan_type' => $scanRule['scan_type'],

            'scan_window' => $windowLabel,

            'event_name' => $event_name,

            'gps_note' => $logoutNote,

            'location_notify' => $notifyResult,

            'already_present' => false,
            'is_rescan' => false,

        ]);

        $conn->commit();

        exit;

    }



    if (!$attendance_id) {

    try {

        $stmt = $conn->prepare("

            INSERT INTO attendance_sessions

            (

                user_id,

                event_id,

                scanned_by_officer_id,

                scan_type,

                status,

                qr_scanned_at,
                location_requested_at,
                is_late_scan
            )
            VALUES
            (?, ?, ?, ?, 'pending', NOW(), NOW(), ?)

        ");

        $stmt->execute([

            $student['user_id'],

            $event_id,

            $_SESSION['user_id'],

            $scanRule['scan_type'],

            $isLateScan ? 1 : 0,

        ]);

        $attendance_id = $conn->lastInsertId();

    } catch (PDOException $e) {

        $stmt = $conn->prepare("

            INSERT INTO attendance_sessions

            (

                user_id,

                event_id,

                scanned_by_officer_id,

                scan_type,

                status,

                qr_scanned_at

            )

            VALUES

            (?, ?, ?, ?, 'pending', NOW())

        ");

        $stmt->execute([

            $student['user_id'],

            $event_id,

            $_SESSION['user_id'],

            $scanRule['scan_type'],

        ]);

        $attendance_id = $conn->lastInsertId();

        try {

            $stmt = $conn->prepare("

                UPDATE attendance_sessions

                SET location_requested_at = NOW()

                WHERE attendance_id = ?

            ");

            $stmt->execute([$attendance_id]);

            $conn->prepare("UPDATE attendance_sessions SET is_late_scan = ? WHERE attendance_id = ?")
                ->execute([$isLateScan ? 1 : 0, $attendance_id]);

        } catch (PDOException $e2) {

            // Column may be missing until migration is applied

        }

    }

    }

    ensurePenaltyResolutionSchema($conn);
    resetMonitoringForNewScan(
        $conn,
        (int) $student['user_id'],
        $event_id,
        (int) $attendance_id
    );

    require_once '../includes/realtime_notify.php';

    $notifyResult = realtime_notify_location_request(
        $conn,
        (int) $student['user_id'],
        (int) $attendance_id
    );

    $gpsNote = 'Location request sent to the student dashboard.';

    if ($notifyResult['clients_notified'] > 0) {
        $gpsNote = 'Location request delivered instantly via WebSocket ('
            . $notifyResult['clients_notified'] . ' device(s)).';
    } elseif ($notifyResult['queued']) {
        $gpsNote = 'Location request queued. Student will be prompted when their dashboard is open.';
    }

    echo json_encode([
        'success' => true,
        'attendance_id' => $attendance_id,
        'user_id' => (int) $student['user_id'],
        'student_no' => $student['student_no'] ?? '',
        'student_name' => trim(
            $student['firstname'] . ' ' . $student['lastname']
        ),
        'message' => ($isLateScan ? 'Late scan recorded for ' : 'Attendance recorded for ')
            . $student['firstname'] . ' ' . $student['lastname']
            . ' (' . ($student['student_no'] ?? '') . ')',
        'scan_type' => $scanRule['scan_type'],
        'scan_window' => $windowLabel,
        'event_name' => $event_name,
        'gps_note' => $gpsNote,
        'location_notify' => $notifyResult,
        'already_present' => false,
        'is_rescan' => false,
    ]);

    logAudit(
        $conn,
        'officer_scan',
        'attendance_sessions',
        (int) $attendance_id,
        trim($student['firstname'] . ' ' . $student['lastname']) . ' — ' . formatScanTypeLabel($scanRule['scan_type'])
            . ($isLateScan ? ' (late scan)' : '')
    );

    $conn->commit();

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        if ((int) $e->getCode() === 23000) {
            throw new Exception('This student is already marked present');
        }

        throw $e;
    }



} catch (Exception $e) {

    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }

    $failDetail = $e->getMessage();

    if (isset($student) && is_array($student)) {
        $failName = trim((string) ($student['firstname'] ?? '') . ' ' . (string) ($student['lastname'] ?? ''));

        if ($failName !== '') {
            $failDetail = $failName . ' — ' . $failDetail;
        }
    }

    if (isset($conn) && $conn instanceof PDO) {
        logAudit($conn, 'officer_scan_failed', 'attendance_sessions', null, $failDetail);
    }

    echo json_encode([

        'success' => false,

        'message' => $e->getMessage(),

    ]);

}



/**

 * Read qr_data from POST (multiple keys), REQUEST fallback, JSON body, or raw urlencoded body.

 */

function readPostedQrData(): string

{

    if (isset($_POST['qr_data'])) {



        $v = $_POST['qr_data'];



        if (is_string($v) && trim($v) !== '') {

            return $v;

        }

    }



    foreach (['qrData', 'data', 'q'] as $key) {



        if (

            isset($_POST[$key]) &&

            is_string($_POST[$key]) &&

            trim($_POST[$key]) !== ''

        ) {

            return $_POST[$key];

        }

    }



    if (

        isset($_REQUEST['qr_data']) &&

        is_string($_REQUEST['qr_data']) &&

        trim($_REQUEST['qr_data']) !== ''

    ) {

        return $_REQUEST['qr_data'];

    }



    $ctype = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';



    $rawBody = @file_get_contents('php://input');



    if (!is_string($rawBody) || $rawBody === '') {

        return '';

    }



    if (stripos($ctype, 'application/json') !== false) {



        $decoded = json_decode($rawBody, true);



        if (

            is_array($decoded) &&

            isset($decoded['qr_data']) &&

            is_string($decoded['qr_data']) &&

            trim($decoded['qr_data']) !== ''

        ) {

            return $decoded['qr_data'];

        }

    }



    if (

        stripos($ctype, 'application/x-www-form-urlencoded') !== false

        || $ctype === ''

        || stripos($ctype, 'text/plain') !== false

    ) {



        $parsed = [];



        parse_str($rawBody, $parsed);



        if (

            isset($parsed['qr_data']) &&

            is_string($parsed['qr_data']) &&

            trim($parsed['qr_data']) !== ''

        ) {

            return $parsed['qr_data'];

        }

    }



    return '';

}



/**

 * Normalize raw QR text from cameras (BOM, zero-width, newlines).

 */

function normalizeQrPayload(string $raw): string

{

    $s = trim($raw);

    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);

    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);

    $s = str_replace(["\n", "\r"], '', $s);

    $s = preg_replace('/[\x{FF5C}\x{2502}\x{2503}\x{FF5F}\x{2016}]/u', '|', $s);



    return trim($s);

}



function resolveStudentFromQrPayload(PDO $conn, string $raw): ?array

{

    $qrData = normalizeQrPayload($raw);



    if ($qrData === '') {

        return null;

    }



    if (strlen($qrData) > 0 && $qrData[0] === '{') {



        $decoded = json_decode($qrData, true);



        if (is_array($decoded)) {



            if (isset($decoded['u'])) {



                $uid = (int) $decoded['u'];



                if ($uid > 0) {



                    $row = lookupStudentByUserId($conn, $uid);



                    if ($row) {

                        return $row;

                    }

                }

            }



            foreach (['s', 'student_no', 'studentNo', 'student_number'] as $key) {



                if (

                    isset($decoded[$key]) &&

                    is_string($decoded[$key]) &&

                    trim($decoded[$key]) !== ''

                ) {



                    $row = lookupStudentByStudentNo($conn, trim($decoded[$key]));



                    if ($row) {

                        return $row;

                    }

                }

            }

        } else {



            if (preg_match('/"u"\s*:\s*(\d+)/', $qrData, $m)) {



                $row = lookupStudentByUserId($conn, (int) $m[1]);



                if ($row) {

                    return $row;

                }

            }



            if (preg_match('/"s"\s*:\s*"([^"]*)"/', $qrData, $m)) {



                $row = lookupStudentByStudentNo($conn, $m[1]);



                if ($row) {

                    return $row;

                }

            }

        }

    }



    $parts = array_map('trim', explode('|', $qrData));

    $candidates = [];



    if (count($parts) >= 2 && $parts[1] !== '') {

        $candidates[] = trim($parts[1]);

    }



    if (count($parts) === 1) {



        $only = trim($parts[0]);



        if ($only !== '' && !in_array($only, $candidates, true)) {

            $candidates[] = $only;

        }

    } else {



        foreach ($parts as $idx => $segment) {



            $trimSeg = trim($segment);



            if ($trimSeg === '' || in_array($trimSeg, $candidates, true)) {

                continue;

            }



            if ($idx === 1) {

                continue;

            }



            if (preg_match('/\d/', $trimSeg)) {

                $candidates[] = $trimSeg;

            }

        }

    }



    foreach ($candidates as $student_no) {



        $row = lookupStudentByStudentNo($conn, $student_no);



        if ($row) {

            return $row;

        }

    }



    return null;

}



function lookupStudentByUserId(PDO $conn, int $user_id): ?array

{

    if ($user_id <= 0) {

        return null;

    }



    $stmt = $conn->prepare("

        SELECT user_id, student_no, firstname, lastname

        FROM users

        WHERE user_id = ?

        AND role_id = 2

        AND is_active = 1

        LIMIT 1

    ");



    $stmt->execute([$user_id]);



    $student = $stmt->fetch(PDO::FETCH_ASSOC);



    return $student ?: null;

}



function lookupStudentByStudentNo(PDO $conn, string $student_no): ?array

{

    $t = trim($student_no);



    if ($t === '') {

        return null;

    }



    $noSpaces = preg_replace('/\s+/u', '', $t);



    $stmt = $conn->prepare("

        SELECT user_id, student_no, firstname, lastname

        FROM users

        WHERE role_id = 2

        AND is_active = 1

        AND (

            student_no = :a

            OR TRIM(student_no) = TRIM(:b)

            OR LOWER(TRIM(student_no)) = LOWER(TRIM(:c))

            OR REPLACE(TRIM(student_no), ' ', '') = :d

        )

        LIMIT 1

    ");



    $stmt->execute([

        ':a' => $t,

        ':b' => $t,

        ':c' => $t,

        ':d' => $noSpaces,

    ]);



    $student = $stmt->fetch(PDO::FETCH_ASSOC);



    return $student ?: null;

}

