<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/maps_helper.php';
require_once '../includes/audit_helpers.php';
require_once 'includes/setup_wizard.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

expirePastEvents($conn);

$event_id = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$step = max(1, min(5, (int) ($_GET['step'] ?? $_POST['step'] ?? 1)));

$message = '';
$messageType = 'info';
$scheduleTypes = eventScheduleTypes();

// ── POST handlers ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['wizard_action'] ?? '';

    if ($action === 'save_event_name' && $event_id > 0) {
        $event_name = trim($_POST['event_name'] ?? '');
        $existing = fetchEventById($conn, $event_id);

        if (!$existing) {
            $message = 'Event not found.';
            $messageType = 'danger';
            $step = 1;
        } elseif ($event_name === '') {
            $message = 'Please enter an event name.';
            $messageType = 'danger';
            $step = 1;
        } else {
            $stmt = $conn->prepare("UPDATE events SET event_name = ? WHERE event_id = ?");
            $stmt->execute([$event_name, $event_id]);
            logAudit(
                $conn,
                'event_renamed',
                'events',
                $event_id,
                "Renamed event to \"{$event_name}\""
            );
            header("Location: event_setup.php?event_id={$event_id}&step=1&renamed=1");
            exit;
        }
    }

    // Step 1: create or update event details
    if ($action === 'save_details') {
        $event_name = trim($_POST['event_name'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $duration_days = (float) ($_POST['duration_days'] ?? 0);
        $half_day_part = ($_POST['half_day_part'] ?? 'am') === 'pm' ? 'pm' : 'am';
        $schedule_type = $_POST['schedule_type'] ?? 'full_day';
        $durationChoices = eventDurationOptions();
        $durationKey = (string) (fmod($duration_days, 1.0) === 0.0 ? (int) $duration_days : $duration_days);

        if (durationHasHalfDay($duration_days)) {
            $schedule_type = 'full_day';
        }

        $end_date = computeEventEndDate($start_date, $duration_days) ?? '';

        if ($event_name === '' || $start_date === '' || $duration_days <= 0) {
            $message = 'Please complete the event name, start date, and duration.';
            $messageType = 'danger';
            $step = 1;
        } elseif (!isset($durationChoices[$durationKey]) && !isset($durationChoices[(string) $duration_days])) {
            $message = 'Please choose a valid event duration.';
            $messageType = 'danger';
            $step = 1;
        } elseif ($end_date === '') {
            $message = 'Could not compute the end date from that duration.';
            $messageType = 'danger';
            $step = 1;
        } elseif (!isset($scheduleTypes[$schedule_type])) {
            $message = 'Please select a valid daily schedule.';
            $messageType = 'danger';
            $step = 1;
        } else {
            try {
                if ($event_id > 0) {
                    $lock = rejectIfEventSetupLocked($conn, $event_id);

                    if (!$lock['ok']) {
                        $message = implode(' ', $lock['errors']);
                        $messageType = 'danger';
                        $step = 1;
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE events
                            SET event_name = ?, start_date = ?, end_date = ?,
                                duration_days = ?, half_day_part = ?, schedule_type = ?
                            WHERE event_id = ?
                        ");
                        $stmt->execute([
                            $event_name,
                            $start_date,
                            $end_date,
                            $duration_days,
                            $half_day_part,
                            $schedule_type,
                            $event_id,
                        ]);
                        deactivateEventOnConfigChange($conn, $event_id);
                        logAudit(
                            $conn,
                            'event_updated',
                            'events',
                            $event_id,
                            "Updated event \"{$event_name}\" ({$start_date} to {$end_date}, {$duration_days} day(s))"
                        );
                        header("Location: event_setup.php?event_id={$event_id}&step=2");
                        exit;
                    }
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO events
                            (event_name, start_date, end_date, duration_days, half_day_part, schedule_type, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, 0)
                    ");
                    $stmt->execute([
                        $event_name,
                        $start_date,
                        $end_date,
                        $duration_days,
                        $half_day_part,
                        $schedule_type,
                    ]);
                    $event_id = (int) $conn->lastInsertId();
                    logAudit(
                        $conn,
                        'event_created',
                        'events',
                        $event_id,
                        "Created event \"{$event_name}\" ({$start_date} to {$end_date}, {$duration_days} day(s))"
                    );
                    header("Location: event_setup.php?event_id={$event_id}&step=2");
                    exit;
                }
            } catch (PDOException $e) {
                $message = 'Error saving event: ' . $e->getMessage()
                    . ' If this mentions unknown column duration_days or scan_date, run database/upgrade_per_day_scan_windows.sql in phpMyAdmin first.';
                $messageType = 'danger';
                $step = 1;
            }
        }
    }

    // Step 3: save per-date scan windows
    if ($action === 'save_scans' && $event_id > 0) {
        $windows = $_POST['scan'] ?? [];

        if (!is_array($windows)) {
            $windows = [];
        }

        $result = saveEventScanRulesBulk($conn, $event_id, $windows);

        if ($result['ok']) {
            logAudit($conn, 'scan_windows_saved', 'event_scan_requirements', $event_id, 'Saved per-day scan windows');
            header("Location: event_setup.php?event_id={$event_id}&step=4");
            exit;
        }

        $message = implode(' ', $result['errors']);
        $messageType = 'danger';
        $step = 3;
    }

    // Step 4: save penalty rules
    if ($action === 'save_penalty' && $event_id > 0) {
        $result = saveEventPenaltyRule(
            $conn,
            $event_id,
            (int) ($_POST['community_service_hours'] ?? 0),
            (float) ($_POST['monetary_amount'] ?? 0),
            trim($_POST['description'] ?? ''),
            (int) ($_POST['late_community_service_hours'] ?? 0),
            (float) ($_POST['late_monetary_amount'] ?? 0)
        );

        if ($result['ok']) {
            logAudit($conn, 'penalty_rules_saved', 'event_penalty_rules', $event_id, 'Saved event penalty rules');
            header("Location: event_setup.php?event_id={$event_id}&step=5");
            exit;
        }

        $message = implode(' ', $result['errors']);
        $messageType = 'danger';
        $step = 4;
    }

    // Step 5: activate
    if ($action === 'activate' && $event_id > 0) {
        $result = activateEvent($conn, $event_id);

        if ($result['ok']) {
            logAudit($conn, 'event_activated', 'events', $event_id, 'Activated event from setup wizard');
            $_SESSION['flash_message'] = 'Event activated! Officers can now scan during scheduled windows.';
            $_SESSION['flash_type'] = 'success';
            header("Location: events.php");
            exit;
        }

        $message = implode(' ', $result['errors']);
        $messageType = 'danger';
        $step = 5;
    }

    if ($action === 'deactivate' && $event_id > 0) {
        deactivateEventOnConfigChange($conn, $event_id);
        logAudit($conn, 'event_deactivated', 'events', $event_id, 'Deactivated event from setup wizard');
        $_SESSION['flash_message'] = 'Event deactivated.';
        $_SESSION['flash_type'] = 'warning';
        header("Location: event_setup.php?event_id={$event_id}&step=5");
        exit;
    }
}

// Steps 2+ require an event
if ($step > 1 && $event_id <= 0) {
    header('Location: event_setup.php?step=1');
    exit;
}

$event = $event_id > 0 ? fetchEventById($conn, $event_id) : null;

if ($event_id > 0 && !$event) {
    header('Location: events.php');
    exit;
}

$readiness = $event_id > 0
    ? getEventReadiness($conn, $event_id)
    : ['ok' => false, 'checks' => [], 'errors' => [], 'expected_stamps' => 0, 'scans_per_day' => 0, 'day_count' => 0];

// Jump to first incomplete step when opening setup without explicit step
if ($event_id > 0 && !isset($_GET['step']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $step = getSuggestedSetupStep($readiness);
}

$setupProgress = getSetupProgress($readiness);
$expired = $event ? isEventExpired($event) : false;
$setupLocked = $event ? isEventSetupLocked($event) : false;

// Load step-specific data
$penaltyRule = null;
$scanRules = [];
$rulesByType = [];
$rulesByDate = [];
$defaults = getDefaultScanTimeWindows();
$durationOptions = eventDurationOptions();
$dayPlans = $event ? getEventDayPlans($event) : [];
$currentDuration = $event
    ? (string) (float) ($event['duration_days'] ?? (count($dayPlans) ?: 1))
    : '1';
$currentHalfPart = is_array($event) ? (string) ($event['half_day_part'] ?? 'am') : 'am';

if ($event_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM penalty_rules WHERE event_id = ? LIMIT 1");
    $stmt->execute([$event_id]);
    $penaltyRule = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $scanRules = getEventScanRules($conn, $event_id);
    foreach ($scanRules as $rule) {
        $date = (string) ($rule['scan_date'] ?? '');
        $type = (string) ($rule['scan_type'] ?? '');
        $rulesByType[$type] = $rule;

        if ($date !== '') {
            $rulesByDate[$date][$type] = $rule;
        }
    }
}

$scheduleType = $event['schedule_type'] ?? 'full_day';
$requiredTypes = getRequiredScanTypes($scheduleType);

$campus = campusMapConfig();

renderAdminHeader('Event Setup Wizard', 'events');
renderSetupWizardStyles();
?>

<section class="admin-panel wizard-shell">
    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Event created! Continue with the steps below.</div>
    <?php endif; ?>

    <?php if (isset($_GET['renamed'])): ?>
        <div class="alert alert-success">Event name saved. Other setup stays locked while this event is active.</div>
    <?php endif; ?>

    <?php if ($setupLocked): ?>
        <div class="alert alert-warning">
            <strong>This event is active.</strong>
            Dates, geofence, scan windows, and penalties cannot be changed until you deactivate it.
            You can still rename the event or
            <a href="extend_scan_windows.php" class="alert-link">extend scan windows</a>.
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h2 class="h4 mb-1">
                <?= $event ? htmlspecialchars($event['event_name']) : 'New Event Setup' ?>
            </h2>
            <p class="text-muted small mb-0">
                Follow each step in order. Use <strong>Save &amp; Continue</strong> to move to the next step.
            </p>
        </div>
        <?php if ($event_id > 0): ?>
            <span class="badge <?= $setupProgress['percent'] === 100 ? 'text-bg-success' : 'text-bg-warning text-dark' ?>">
                <?= $setupProgress['percent'] ?>% complete
            </span>
        <?php endif; ?>
    </div>

    <?php if ($event_id > 0): ?>
        <div class="progress mb-3" style="height: 6px;">
            <div class="progress-bar" style="width: <?= $setupProgress['percent'] ?>%"></div>
        </div>
        <?php renderSetupWizardStepper($step, $event_id, $readiness); ?>
    <?php else: ?>
        <div class="wizard-steps mb-3">
            <div class="wizard-step active"><span class="step-num">1</span> Details</div>
            <div class="wizard-step disabled"><span class="step-num">2</span> Geofence</div>
            <div class="wizard-step disabled"><span class="step-num">3</span> Scans</div>
            <div class="wizard-step disabled"><span class="step-num">4</span> Penalties</div>
            <div class="wizard-step disabled"><span class="step-num">5</span> Review</div>
        </div>
    <?php endif; ?>

    <div class="wizard-card">

        <?php if ($step === 1): ?>
            <!-- STEP 1: Event Details -->
            <h3>Step 1 — Event Details</h3>
            <p class="text-muted small mb-4">
                Name your event, set the date range, and choose how many stamps students need per day.
            </p>

            <form method="POST">
                <input type="hidden" name="wizard_action" value="<?= $setupLocked ? 'save_event_name' : 'save_details' ?>">
                <input type="hidden" name="event_id" value="<?= $event_id ?>">
                <input type="hidden" name="step" value="1">

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Event Name</label>
                        <input type="text" name="event_name" class="form-control"
                            value="<?= htmlspecialchars($event['event_name'] ?? '') ?>"
                            placeholder="e.g. College Foundation Day 2026" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" id="eventStartDate"
                            value="<?= htmlspecialchars($event['start_date'] ?? '') ?>"
                            <?= $setupLocked ? 'disabled' : 'required' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Event Duration</label>
                        <select name="duration_days" class="form-select" id="eventDuration" <?= $setupLocked ? 'disabled' : 'required' ?>>
                            <?php foreach ($durationOptions as $value => $label): ?>
                                <option value="<?= htmlspecialchars((string) $value) ?>"
                                    <?= ((string) (float) $currentDuration === (string) (float) $value) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="eventEndDateHint">
                            <?php if (!empty($event['end_date'])): ?>
                                End date will be <?= htmlspecialchars($event['end_date']) ?> (<?= (int) count($dayPlans) ?> date<?= count($dayPlans) === 1 ? '' : 's' ?> to fill in).
                            <?php else: ?>
                                End date is computed from the start date and duration.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12" id="halfDayPartWrap">
                        <label class="form-label">Last half day is</label>
                        <select name="half_day_part" class="form-select" <?= $setupLocked ? 'disabled' : '' ?>>
                            <option value="am" <?= $currentHalfPart === 'am' ? 'selected' : '' ?>>Morning (AM login + AM logout only)</option>
                            <option value="pm" <?= $currentHalfPart === 'pm' ? 'selected' : '' ?>>Afternoon (PM login + PM logout only)</option>
                        </select>
                        <div class="form-text">
                            Example: 3.5 days = 4 dates. The first 3 dates have 4 stamps each; the last date has 2 stamps.
                        </div>
                    </div>
                    <div class="col-12" id="scheduleTypeWrap">
                        <label class="form-label">Daily Schedule (when duration is whole days only)</label>
                        <select name="schedule_type" class="form-select" <?= $setupLocked ? 'disabled' : '' ?>>
                            <?php foreach ($scheduleTypes as $key => $meta): ?>
                                <option value="<?= htmlspecialchars($key) ?>"
                                    <?= ($scheduleType === $key) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($meta['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Use this only when every day is the same (all whole days, or every day is a half day). Mixed 3.5-day events use the option above instead.
                        </div>
                    </div>
                </div>
                <?php if (!$setupLocked): ?>
                <script>
                (function () {
                    const duration = document.getElementById('eventDuration');
                    const start = document.getElementById('eventStartDate');
                    const hint = document.getElementById('eventEndDateHint');
                    const halfWrap = document.getElementById('halfDayPartWrap');
                    const scheduleWrap = document.getElementById('scheduleTypeWrap');

                    function hasHalf(value) {
                        return Math.abs(parseFloat(value) - Math.floor(parseFloat(value)) - 0.5) < 0.01;
                    }

                    function calendarDays(value) {
                        return Math.ceil(parseFloat(value) - 0.0001);
                    }

                    function addDays(dateStr, extra) {
                        const d = new Date(dateStr + 'T00:00:00');
                        d.setDate(d.getDate() + extra);
                        return d.toISOString().slice(0, 10);
                    }

                    function refresh() {
                        const dur = duration.value;
                        const half = hasHalf(dur);
                        halfWrap.style.display = half ? '' : 'none';
                        scheduleWrap.style.display = half ? 'none' : '';
                        const days = calendarDays(dur);
                        if (start.value && days > 0) {
                            const end = addDays(start.value, days - 1);
                            hint.textContent = 'End date will be ' + end + ' (' + days + ' date' + (days === 1 ? '' : 's') + ' to fill in).';
                        }
                    }

                    duration.addEventListener('change', refresh);
                    start.addEventListener('change', refresh);
                    refresh();
                })();
                </script>
                <?php else: ?>
                <script>
                (function () {
                    const dur = parseFloat(document.getElementById('eventDuration').value);
                    const half = Math.abs(dur - Math.floor(dur) - 0.5) < 0.01;
                    document.getElementById('halfDayPartWrap').style.display = half ? '' : 'none';
                    document.getElementById('scheduleTypeWrap').style.display = half ? 'none' : '';
                })();
                </script>
                <?php endif; ?>

                <?php
                if ($setupLocked) {
                    renderWizardNav(1, $event_id, null, "event_setup.php?event_id={$event_id}&step=2", true, 'Save Name', true);
                } else {
                    renderWizardNav(1, $event_id, null, null, true, 'Save & Continue');
                }
                ?>
            </form>

        <?php elseif ($step === 2): ?>
            <!-- STEP 2: Geofence -->
            <h3>Step 2 — Geofence Boundary</h3>
            <p class="text-muted small mb-3">
                <?php if ($setupLocked): ?>
                    Geofence is locked while this event is active. Deactivate the event to move or resize it.
                <?php else: ?>
                    Click on the map to set the campus center, adjust the radius, then click <strong>Save Geofence</strong>.
                    Students must be inside this area during scans.
                <?php endif; ?>
            </p>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Radius (meters)</label>
                    <input type="number" id="radiusInput" class="form-control"
                        value="<?= (int) ($event['geofence_radius'] ?? 100) ?>" min="1"
                        <?= $setupLocked ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="button" class="btn btn-success w-100" id="saveGeofenceBtn" <?= $setupLocked ? 'disabled' : '' ?>>
                        Save Geofence
                    </button>
                </div>
            </div>

            <div id="geofenceResult" class="alert alert-info py-2 small">
                <?php if (eventHasGeofence($event)): ?>
                    Geofence already saved. Click the map to change it, or continue to the next step.
                <?php else: ?>
                    Click on the map to place the geofence center point.
                <?php endif; ?>
            </div>

            <div id="wizardMap" style="height: 420px; width: 100%; border-radius: 10px; border: 1px solid #d7def2;"></div>

            <div class="wizard-nav">
                <a href="event_setup.php?event_id=<?= $event_id ?>&step=1" class="btn btn-outline-secondary">← Back</a>
                <?php if (eventHasGeofence($event)): ?>
                    <a href="event_setup.php?event_id=<?= $event_id ?>&step=3" class="btn btn-primary">Continue to Scan Windows →</a>
                <?php else: ?>
                    <button type="button" class="btn btn-primary" disabled id="geofenceNextBtn" title="Save geofence first">Continue to Scan Windows →</button>
                <?php endif; ?>
            </div>

            <script src="../assets/js/admin-maps.js"></script>
            <script>
            (function () {
                const eventId = <?= (int) $event_id ?>;
                const setupLocked = <?= $setupLocked ? 'true' : 'false' ?>;
                const campusMap = <?= json_encode($campus, JSON_UNESCAPED_UNICODE) ?>;
                let map, marker, circle, selectedLat, selectedLng;
                const initLat = <?= eventHasGeofence($event) ? (float) $event['geofence_latitude'] : 'null' ?>;
                const initLng = <?= eventHasGeofence($event) ? (float) $event['geofence_longitude'] : 'null' ?>;
                const hasGeofence = <?= eventHasGeofence($event) ? 'true' : 'false' ?>;

                function initMap() {
                    const center = {
                        lat: initLat !== null ? initLat : campusMap.latitude,
                        lng: initLng !== null ? initLng : campusMap.longitude,
                    };
                    map = new google.maps.Map(document.getElementById('wizardMap'), EpassportMaps.createOptions({
                        center,
                        zoom: campusMap.default_zoom || 17,
                        minZoom: 3,
                        maxZoom: 22,
                        mapTypeId: 'hybrid',
                        gestureHandling: 'greedy',
                        clickableIcons: true,
                        draggable: true,
                        scrollwheel: true,
                    }));

                    EpassportMaps.applyMaxZoomAt(map, new google.maps.LatLng(center.lat, center.lng));

                    if (hasGeofence && initLat && initLng) {
                        selectedLat = initLat;
                        selectedLng = initLng;
                        placeMarker(center);
                        EpassportMaps.fitToGeofence(map, initLat, initLng, parseInt(document.getElementById('radiusInput').value, 10) || 100);
                    } else {
                        EpassportMaps.focusOpeningArea(map, campusMap);
                    }

                    map.addListener('click', function (e) {
                        if (setupLocked) {
                            return;
                        }
                        selectedLat = e.latLng.lat();
                        selectedLng = e.latLng.lng();
                        placeMarker(e.latLng);
                    });
                }

                function placeMarker(latLng) {
                    if (marker) marker.setMap(null);
                    if (circle) circle.setMap(null);
                    const radius = parseInt(document.getElementById('radiusInput').value, 10) || 100;
                    marker = new google.maps.Marker({ position: latLng, map });
                    circle = new google.maps.Circle({
                        map, center: latLng, radius,
                        fillColor: '#1f2f88', fillOpacity: 0.2, strokeColor: '#1f2f88'
                    });
                }

                document.getElementById('saveGeofenceBtn').addEventListener('click', function () {
                    if (setupLocked) {
                        return;
                    }
                    const radius = document.getElementById('radiusInput').value;
                    const result = document.getElementById('geofenceResult');

                    if (!selectedLat || !selectedLng) {
                        result.className = 'alert alert-warning py-2 small';
                        result.textContent = 'Please click on the map first to set the center point.';
                        return;
                    }

                    fetch('../ajax/save_geofence.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'event_id=' + eventId + '&lat=' + selectedLat + '&lng=' + selectedLng + '&radius=' + radius
                    })
                    .then(r => r.json())
                    .then(data => {
                        result.className = 'alert py-2 small alert-' + (data.success ? 'success' : 'danger');
                        result.textContent = data.message;
                        if (data.success) {
                            const next = document.getElementById('geofenceNextBtn');
                            if (next) {
                                next.outerHTML = '<a href="event_setup.php?event_id=' + eventId + '&step=3" class="btn btn-primary">Continue to Scan Windows →</a>';
                            }
                        }
                    });
                });

                window.initWizardGeofenceMap = function () {
                    if (window.__wizardGeofenceMapBooted) {
                        return;
                    }
                    window.__wizardGeofenceMapBooted = true;
                    initMap();
                };
            })();
            </script>
            <?= googleMapsApiScript('initWizardGeofenceMap', '../') ?>

        <?php elseif ($step === 3): ?>
            <!-- STEP 3: Scan Windows -->
            <h3>Step 3 — Scan Windows</h3>
            <p class="text-muted small mb-3">
                Each date has its own login and logout times. A 3.5-day event has 4 dates:
                3 full days (4 stamps) plus one half day (2 stamps).
                <strong><?= (int) $readiness['expected_stamps'] ?> total stamps</strong>
                across <?= (int) $readiness['day_count'] ?> date(s).
                <?php if ($setupLocked): ?>
                    Windows are locked while the event is active.
                    <a href="extend_scan_windows.php">Extend a window</a> if officers need extra time.
                <?php endif; ?>
            </p>

            <form method="POST">
                <input type="hidden" name="wizard_action" value="save_scans">
                <input type="hidden" name="event_id" value="<?= $event_id ?>">
                <input type="hidden" name="step" value="3">

                <?php if ($dayPlans === []): ?>
                    <div class="alert alert-warning">Save event duration and start date in Step 1 first.</div>
                <?php endif; ?>

                <?php foreach ($dayPlans as $index => $day): ?>
                    <?php
                    $dayTypes = getRequiredScanTypes($day['schedule_type']);
                    $stampCount = count($dayTypes);
                    $dayNumber = $index + 1;
                    ?>
                    <div class="border rounded-3 p-3 mb-3">
                        <h4 class="h6 mb-1">
                            Day <?= (int) $dayNumber ?> — <?= htmlspecialchars($day['date']) ?>
                            <span class="text-muted fw-normal">(<?= htmlspecialchars($day['label']) ?>, <?= (int) $stampCount ?> stamp<?= $stampCount === 1 ? '' : 's' ?>)</span>
                        </h4>
                        <?php foreach ($dayTypes as $type): ?>
                            <?php
                            $existing = $rulesByDate[$day['date']][$type] ?? $rulesByType[$type] ?? null;
                            $def = $defaults[$type] ?? ['start' => '08:00', 'end' => '09:00'];
                            $startVal = $existing ? substr($existing['start_time'], 0, 5) : $def['start'];
                            $endVal = $existing ? substr($existing['end_time'], 0, 5) : $def['end'];
                            ?>
                            <div class="scan-row row g-2 align-items-center">
                                <div class="col-md-4">
                                    <label><?= htmlspecialchars(formatScanTypeLabel($type)) ?></label>
                                    <div class="small text-muted"><code><?= htmlspecialchars($type) ?></code></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Start</label>
                                    <input type="time"
                                        name="scan[<?= htmlspecialchars($day['date']) ?>][<?= htmlspecialchars($type) ?>][start]"
                                        class="form-control" value="<?= htmlspecialchars($startVal) ?>"
                                        <?= $setupLocked ? 'disabled' : 'required' ?>>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">End</label>
                                    <input type="time"
                                        name="scan[<?= htmlspecialchars($day['date']) ?>][<?= htmlspecialchars($type) ?>][end]"
                                        class="form-control" value="<?= htmlspecialchars($endVal) ?>"
                                        <?= $setupLocked ? 'disabled' : 'required' ?>>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <?php
                if ($setupLocked) {
                    renderWizardNav(3, $event_id, "event_setup.php?event_id={$event_id}&step=2", "event_setup.php?event_id={$event_id}&step=4", true, 'Continue', false);
                } else {
                    renderWizardNav(3, $event_id, "event_setup.php?event_id={$event_id}&step=2", null, true, 'Save & Continue');
                }
                ?>
            </form>

        <?php elseif ($step === 4): ?>
            <!-- STEP 4: Penalty Rules -->
            <h3>Step 4 — Penalty Rules</h3>
            <p class="text-muted small mb-4">
                <?php if ($setupLocked): ?>
                    Penalty rules are locked while this event is active. Deactivate the event to change them.
                <?php else: ?>
                    Set consequences for a <strong>missed stamp</strong> and for students scanned with
                    <strong>Scan Late Students</strong> after the window closes.
                <?php endif; ?>
            </p>

            <form method="POST">
                <input type="hidden" name="wizard_action" value="save_penalty">
                <input type="hidden" name="event_id" value="<?= $event_id ?>">
                <input type="hidden" name="step" value="4">

                <h4 class="h6">Missed stamp / absent</h4>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Community Service Hours (per missed stamp)</label>
                        <input type="number" name="community_service_hours" class="form-control" min="0"
                            value="<?= (int) ($penaltyRule['community_service_hours'] ?? 2) ?>"
                            <?= $setupLocked ? 'disabled' : 'required' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Monetary Amount ₱ (per missed stamp)</label>
                        <input type="number" name="monetary_amount" class="form-control" min="0" step="0.01"
                            value="<?= htmlspecialchars((string) ($penaltyRule['monetary_amount'] ?? '50.00')) ?>"
                            <?= $setupLocked ? 'disabled' : 'required' ?>>
                    </div>
                </div>

                <h4 class="h6">Late scan (after the window)</h4>
                <p class="text-muted small">Applied when an officer uses Scan Late Students and the student is verified inside the geofence (or logout is recorded late).</p>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Community Service Hours (per late stamp)</label>
                        <input type="number" name="late_community_service_hours" class="form-control" min="0"
                            value="<?= (int) ($penaltyRule['late_community_service_hours'] ?? 1) ?>"
                            <?= $setupLocked ? 'disabled' : 'required' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Monetary Amount ₱ (per late stamp)</label>
                        <input type="number" name="late_monetary_amount" class="form-control" min="0" step="0.01"
                            value="<?= htmlspecialchars((string) ($penaltyRule['late_monetary_amount'] ?? '25.00')) ?>"
                            <?= $setupLocked ? 'disabled' : 'required' ?>>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description / Notes</label>
                        <textarea name="description" class="form-control" rows="2"
                            placeholder="e.g. Report to SSC office for community service"
                            <?= $setupLocked ? 'disabled' : '' ?>><?= htmlspecialchars($penaltyRule['description'] ?? '') ?></textarea>
                    </div>
                </div>

                <?php
                if ($setupLocked) {
                    renderWizardNav(4, $event_id, "event_setup.php?event_id={$event_id}&step=3", "event_setup.php?event_id={$event_id}&step=5", true, 'Continue', false);
                } else {
                    renderWizardNav(4, $event_id, "event_setup.php?event_id={$event_id}&step=3", null, true, 'Save & Continue');
                }
                ?>
            </form>

        <?php elseif ($step === 5): ?>
            <!-- STEP 5: Review & Activate -->
            <h3>Step 5 — Review &amp; Activate</h3>
            <p class="text-muted small mb-4">Review everything before going live. Officers can scan only during this event's dates and scan windows.</p>

            <?php if ($expired): ?>
                <div class="alert alert-secondary">This event ended on <?= htmlspecialchars($event['end_date']) ?> and cannot be activated.</div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="border rounded-3 p-3 h-100 <?= $readiness['checks']['details']['ok'] ? 'border-success' : 'border-warning' ?>">
                        <h4 class="h6">Event Details <?= $readiness['checks']['details']['ok'] ? '✓' : '○' ?></h4>
                        <p class="small mb-0">
                            <strong><?= htmlspecialchars($event['event_name']) ?></strong><br>
                            <?= htmlspecialchars($event['start_date']) ?> → <?= htmlspecialchars($event['end_date']) ?><br>
                            Duration: <?= htmlspecialchars((string) ($event['duration_days'] ?? '')) ?> day(s)
                            · <?= (int) $readiness['expected_stamps'] ?> stamps across <?= (int) $readiness['day_count'] ?> date(s)
                        </p>
                        <a href="?event_id=<?= $event_id ?>&step=1" class="btn btn-sm btn-link p-0 mt-1"><?= $setupLocked ? 'View' : 'Edit' ?></a>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded-3 p-3 h-100 <?= $readiness['checks']['geofence']['ok'] ? 'border-success' : 'border-warning' ?>">
                        <h4 class="h6">Geofence <?= $readiness['checks']['geofence']['ok'] ? '✓' : '○' ?></h4>
                        <p class="small mb-0">
                            <?php if (eventHasGeofence($event)): ?>
                                Center: <?= htmlspecialchars((string) $event['geofence_latitude']) ?>, <?= htmlspecialchars((string) $event['geofence_longitude']) ?><br>
                                Radius: <?= (int) $event['geofence_radius'] ?> meters
                            <?php else: ?>
                                <span class="text-danger">Not configured</span>
                            <?php endif; ?>
                        </p>
                        <a href="?event_id=<?= $event_id ?>&step=2" class="btn btn-sm btn-link p-0 mt-1"><?= $setupLocked ? 'View' : 'Edit' ?></a>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded-3 p-3 h-100 <?= $readiness['checks']['scan_rules']['ok'] ? 'border-success' : 'border-warning' ?>">
                        <h4 class="h6">Scan Windows <?= $readiness['checks']['scan_rules']['ok'] ? '✓' : '○' ?></h4>
                        <ul class="small mb-0 ps-3">
                            <?php foreach ($dayPlans as $day): ?>
                                <?php $dayTypes = getRequiredScanTypes($day['schedule_type']); ?>
                                <li class="mb-1">
                                    <strong><?= htmlspecialchars($day['date']) ?></strong>
                                    (<?= htmlspecialchars($day['label']) ?>):
                                    <?php foreach ($dayTypes as $type): ?>
                                        <?php $r = $rulesByDate[$day['date']][$type] ?? null; ?>
                                        <?= htmlspecialchars(formatScanTypeLabel($type)) ?>
                                        <?php if ($r): ?>
                                            <?= htmlspecialchars(formatAppTimeRange($r['start_time'], $r['end_time'])) ?>;
                                        <?php else: ?>
                                            <span class="text-danger">missing</span>;
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="?event_id=<?= $event_id ?>&step=3" class="btn btn-sm btn-link p-0 mt-1"><?= $setupLocked ? 'View' : 'Edit' ?></a>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded-3 p-3 h-100 <?= $readiness['checks']['penalty_rules']['ok'] ? 'border-success' : 'border-warning' ?>">
                        <h4 class="h6">Penalties <?= $readiness['checks']['penalty_rules']['ok'] ? '✓' : '○' ?></h4>
                        <p class="small mb-0">
                            <?php if ($penaltyRule): ?>
                                <?= (int) $penaltyRule['community_service_hours'] ?> hrs ·
                                ₱<?= number_format((float) $penaltyRule['monetary_amount'], 2) ?> per missed stamp<br>
                                Late scan: <?= (int) ($penaltyRule['late_community_service_hours'] ?? 0) ?> hrs ·
                                ₱<?= number_format((float) ($penaltyRule['late_monetary_amount'] ?? 0), 2) ?>
                            <?php else: ?>
                                <span class="text-danger">Not configured</span>
                            <?php endif; ?>
                        </p>
                        <a href="?event_id=<?= $event_id ?>&step=4" class="btn btn-sm btn-link p-0 mt-1"><?= $setupLocked ? 'View' : 'Edit' ?></a>
                    </div>
                </div>
            </div>

            <?php if (!$readiness['ok']): ?>
                <div class="alert alert-warning">
                    <strong>Complete these before activating:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($readiness['errors'] as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="wizard-nav">
                <a href="event_setup.php?event_id=<?= $event_id ?>&step=4" class="btn btn-outline-secondary">← Back</a>
                <div class="d-flex gap-2">
                    <?php if ((int) $event['is_active'] === 1 && !$expired): ?>
                        <form method="POST" class="d-inline" data-confirm="Deactivate this event? Officers will not be able to scan until it is activated again." data-confirm-title="Deactivate event" data-confirm-label="Deactivate">
                            <input type="hidden" name="event_id" value="<?= $event_id ?>">
                            <button type="submit" class="btn btn-warning">Deactivate Event</button>
                        </form>
                    <?php elseif ($readiness['ok'] && !$expired): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="wizard_action" value="activate">
                            <input type="hidden" name="event_id" value="<?= $event_id ?>">
                            <button type="submit" class="btn btn-success btn-lg">Activate Event</button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn btn-success btn-lg" disabled>Activate Event</button>
                    <?php endif; ?>
                    <a href="events.php" class="btn btn-outline-secondary">Finish Later</a>
                </div>
            </div>
        <?php endif; ?>

    </div>
</section>

<?php renderAdminFooter(); ?>
