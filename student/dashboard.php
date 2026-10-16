<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../includes/https_helper.php';
epassport_require_https_for_gps();

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/realtime_config.php';

$pollConfig = epassport_realtime_config();

require_once '../includes/penalty_helpers.php';
require_once '../includes/student_layout.php';

$db = new Database();
$conn = $db->connect();

$stmt = $conn->prepare("
    SELECT user_id, student_no, firstname, lastname, course, year_level
    FROM users
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Student not found.');
}

$activeEvent = getActiveEvent($conn);
$nextScan = $activeEvent ? getNextScanWindow($conn, $activeEvent) : null;
$nextScanText = formatNextScanDisplay($nextScan, $conn);
$penaltySummary = getStudentPenaltySummary($conn, (int) $student['user_id']);

$qrPayload = [
    'u' => (int) $student['user_id'],
    's' => trim((string) $student['student_no']),
];
$qrPayloadJson = json_encode($qrPayload, JSON_UNESCAPED_UNICODE);

if ($qrPayloadJson === false) {
    die('Unable to build QR payload.');
}

renderStudentHeader('Dashboard', 'dashboard');
?>

<div class="student-page-intro">
    <h2 class="student-welcome">Hello, <?= htmlspecialchars($student['firstname']) ?>!</h2>
    <p class="student-welcome-note mb-0">
        Show your QR code to the officer for login and logout scans. After a <strong>login</strong> scan, keep this tab open with Location ON until logout.
    </p>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="student-panel student-panel--qr">
            <div class="card-body text-center">
                <h4 class="qr-title mb-1"><i class="bi bi-qr-code-scan me-1"></i>Your QR Code</h4>
                <p class="qr-note mb-3">Present this code to the attendance officer when prompted.</p>

                <?php if ($nextScanText): ?>
                    <div class="student-next-scan student-next-scan--center mb-3">
                        <i class="bi bi-clock"></i>
                        <span><?= htmlspecialchars($nextScanText) ?></span>
                    </div>
                <?php endif; ?>

                <div class="qr-frame mx-auto">
                    <div id="qrcode" class="qr-canvas-wrap"></div>
                </div>

                <div id="gpsStatus" class="status-stack">
                    <div id="realtimeStatus" class="status-chip status-chip--info">
                        <i class="bi bi-broadcast"></i>
                        <span>Connecting to location service…</span>
                    </div>
                    <div id="sessionStatus" class="status-chip status-chip--neutral">
                        <i class="bi bi-info-circle"></i>
                        <span>Waiting for attendance session…</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="student-panel">
            <div class="card-body student-stat-card student-stat-card--danger">
                <h6><i class="bi bi-cash-coin me-1"></i> Total accumulated penalty</h6>
                <strong>₱<?= number_format((float) $penaltySummary['pending_monetary'], 2) ?></strong>
                <p class="small mb-2">or <?= (int) $penaltySummary['pending_service_hours'] ?> community service hour(s) remaining</p>
                <a href="penalties.php" class="small fw-semibold">View penalty details</a>
            </div>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars(epassport_vendor_asset('bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= htmlspecialchars(epassport_vendor_asset('qrcodejs/qrcode.min.js')) ?>"></script>
<script src="../assets/js/background-monitor.js?v=1"></script>
<script src="../assets/js/location-bridge.js?v=21"></script>
<script src="../assets/js/gps.js?v=22"></script>
<script src="../assets/js/student-realtime.js"></script>

<script>
    const qrDataText = <?= json_encode($qrPayloadJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;

    (function renderStudentQr() {
        const container = document.getElementById('qrcode');
        if (!container) return;

        if (typeof QRCode === 'undefined') {
            container.innerHTML = '<p class="text-danger small mb-0">QR library failed to load. Refresh the page.</p>';
            return;
        }

        if (!qrDataText || String(qrDataText).trim() === '') {
            container.innerHTML = '<p class="text-danger small mb-0">Missing student data for QR code.</p>';
            return;
        }

        container.innerHTML = '';

        try {
            const qrSize = Math.min(260, Math.max(200, container.parentElement.clientWidth - 24));
            new QRCode(container, {
                text: String(qrDataText),
                width: qrSize,
                height: qrSize,
                correctLevel: QRCode.CorrectLevel.M
            });
        } catch (err) {
            container.innerHTML = '<p class="text-danger small mb-0">Could not generate QR code. Please refresh.</p>';
        }
    })();

    let activeAttendanceId = null;
    let promptedAttendanceId = null;

    function setStatusChip(el, tone, icon, message) {
        if (!el) return;
        el.className = 'status-chip status-chip--' + tone;
        el.textContent = '';
        const iconEl = document.createElement('i');
        iconEl.className = 'bi bi-' + icon;
        const spanEl = document.createElement('span');
        spanEl.textContent = message || '';
        el.appendChild(iconEl);
        el.appendChild(spanEl);
    }

    function setRealtimeStatus(state, message) {
        const map = {
            online: ['success', 'wifi'],
            connecting: ['info', 'broadcast'],
            offline: ['warning', 'wifi-off']
        };
        const config = map[state] || ['neutral', 'broadcast'];
        setStatusChip(document.getElementById('realtimeStatus'), config[0], config[1], message || '');
    }

    function setSessionStatus(tone, icon, message) {
        setStatusChip(document.getElementById('sessionStatus'), tone, icon, message);
    }

    const pollIntervals = {
        online: <?= (int) ($pollConfig['poll_interval_ws_online'] ?? 10) ?> * 1000,
        offline: <?= (int) ($pollConfig['poll_interval_ws_offline'] ?? 2) ?> * 1000,
    };
    let pollIntervalMs = pollIntervals.offline;
    let pollTimer = null;

    function scheduleAttendancePoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
        }
        pollTimer = setInterval(checkAttendanceSession, pollIntervalMs);
    }

    window.addEventListener('epassport:realtime-status', function (event) {
        setRealtimeStatus(event.detail.state, event.detail.message);
        pollIntervalMs = event.detail.state === 'online'
            ? pollIntervals.online
            : pollIntervals.offline;
        scheduleAttendancePoll();
    });

    window.addEventListener('epassport:location-flow-ended', function () {
        if (!isContinuousMonitoringActive()) {
            activeAttendanceId = null;
            promptedAttendanceId = null;
        }
    });

    window.addEventListener('epassport:logout-reminder', function (event) {
        if (typeof handleLogoutReminder === 'function') {
            handleLogoutReminder(event.detail || {});
        }
    });

    window.addEventListener('epassport:location-request', function (event) {
        const id = parseInt((event.detail || {}).attendance_id, 10);
        if (!id) return;
        activeAttendanceId = id;
        promptedAttendanceId = id;
        handleLocationRequest(id, { realtime_event_id: event.detail.realtime_event_id || null });
    });

    async function checkAttendanceSession() {
        try {
            const response = await fetch('../ajax/get_active_attendance.php', { credentials: 'same-origin' });
            const data = await response.json();

            if (data.should_prompt_location && data.attendance_id) {
                activeAttendanceId = data.attendance_id;
                if (promptedAttendanceId !== data.attendance_id) {
                    promptedAttendanceId = data.attendance_id;
                    handleLocationRequest(data.attendance_id, { realtime_event_id: data.mark_realtime_event_id || null });
                }
            } else if (data.should_prompt_logout_reminder) {
                if (typeof handleLogoutReminder === 'function') {
                    handleLogoutReminder(data);
                }
            } else if (data.state === 'idle' && !gpsActive && !isContinuousMonitoringActive()) {
                activeAttendanceId = null;
                promptedAttendanceId = null;
                setSessionStatus('neutral', 'info-circle', data.message || 'No pending scan. Show your QR code to the officer when ready.');
            } else if ((data.state === 'expired' || data.state === 'processing') && !isContinuousMonitoringActive()) {
                activeAttendanceId = null;
                promptedAttendanceId = null;
                setSessionStatus('warning', 'exclamation-triangle', data.message || 'Attendance session update received.');
            }
        } catch (err) {
            setSessionStatus('danger', 'exclamation-octagon', 'Error checking attendance: ' + err.message);
        }
    }

    if (typeof StudentRealtime !== 'undefined') StudentRealtime.connect();
    checkAttendanceSession();
    scheduleAttendancePoll();
    resumeMonitoringIfNeeded();
</script>

<?php renderStudentFooter(); ?>
