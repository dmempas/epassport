<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/maps_helper.php';
require_once '../includes/monitoring_helpers.php';
require_once '../includes/realtime_config.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

$activeEvent = getActiveEvent($conn);
$activeGeofence = null;
$campus = campusMapConfig();
$mapOptions = googleMapsDefaultOptions($campus);
$liveTrackingPollSeconds = (int) (epassport_realtime_config()['live_tracking_poll_seconds'] ?? 12);

if ($liveTrackingPollSeconds < 5) {
    $liveTrackingPollSeconds = 5;
}

if ($activeEvent && eventHasGeofence($activeEvent)) {
    $geofenceDisplay = geofenceDisplayConfig();
    $activeGeofence = [
        'event_name' => $activeEvent['event_name'],
        'lat' => (float) $activeEvent['geofence_latitude'],
        'lng' => (float) $activeEvent['geofence_longitude'],
        'radius' => (int) $activeEvent['geofence_radius'],
        'exit_buffer_m' => $geofenceDisplay['exit_buffer_m'],
    ];
}

renderAdminHeader('Live Student Tracking', 'live_tracking');
?>

<section class="admin-panel tracking-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Live Student Monitoring</h2>
            <p class="text-muted small mb-0">Real-time GPS positions and geofence violation alerts</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($activeGeofence): ?>
                <span class="badge badge-soft-gold"><?= htmlspecialchars($activeGeofence['event_name']) ?></span>
            <?php endif; ?>
            <a href="geofence_map.php" class="btn btn-outline-primary btn-sm">Manage Geofence</a>
        </div>
    </div>

    <?php if (!$activeGeofence): ?>
        <div class="alert alert-warning py-2 small mb-3">
            No active event with a geofence configured. Activate an event and set its boundary to enable live monitoring.
        </div>
    <?php endif; ?>

    <div class="tracking-map-wrap">
        <div id="liveTrackingMap" class="admin-map tracking-map" aria-label="Live student tracking map"></div>
        <div class="tracking-legend">
            <span><span class="tracking-dot tracking-dot--inside"></span> Inside boundary</span>
            <span><span class="tracking-dot tracking-dot--outside"></span> Outside boundary</span>
            <span><span class="tracking-dot tracking-dot--violated"></span> Violated</span>
            <span><span class="tracking-dot tracking-dot--stale-ring"></span> Stale GPS ring</span>
            <span class="tracking-legend-divider">|</span>
            <span class="tracking-legend-boundary"><span class="tracking-ring tracking-ring--nominal"></span> Geofence</span>
            <span class="tracking-legend-boundary"><span class="tracking-ring tracking-ring--buffer"></span> Monitoring +<?= (int) ($activeGeofence['exit_buffer_m'] ?? 10) ?>m</span>
            <span id="trackedStudentCount"><strong>0</strong> on map</span>
            <span class="text-muted"><i class="bi bi-arrow-repeat"></i> Refreshes every <?= (int) $liveTrackingPollSeconds ?>s</span>
        </div>
    </div>

    <div class="tracking-alerts mt-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h3 class="h6 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Alerts &amp; Violations</h3>
            <div class="d-flex gap-1">
                <span id="watchCount" class="badge text-bg-warning d-none">0 watch</span>
                <span id="violationCount" class="badge text-bg-danger">0</span>
            </div>
        </div>
        <div id="violationsList" class="tracking-violations-list">
            <div class="text-muted small py-3 text-center">Loading monitoring data…</div>
        </div>
    </div>
</section>

<style>
    .tracking-panel .admin-map {
        height: min(58vh, 560px);
        min-height: 420px;
        width: 100%;
        border-radius: 10px;
        border: 1px solid #d7def2;
        position: relative;
        z-index: 1;
    }

    .tracking-map-wrap {
        position: relative;
    }

    .tracking-legend {
        position: absolute;
        bottom: 12px;
        left: 12px;
        z-index: 2;
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
        padding: 0.45rem 0.75rem;
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid #d7dee8;
        border-radius: 999px;
        font-size: 0.78rem;
        color: #5f6791;
        box-shadow: 0 4px 14px rgba(20, 30, 55, 0.08);
    }

    .tracking-dot {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        margin-right: 4px;
        vertical-align: middle;
        border: 2px solid #fff;
        box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.12);
    }

    .tracking-dot--inside { background: #198754; }
    .tracking-dot--outside { background: #dc3545; }
    .tracking-dot--violated { background: #6f42c1; }
    .tracking-dot--stale-ring {
        background: #198754;
        box-shadow: 0 0 0 2px #fff, 0 0 0 4px #fd7e14;
    }

    .tracking-legend-divider { color: #c8cfe6; }
    .tracking-legend-boundary { display: inline-flex; align-items: center; gap: 4px; }
    .tracking-ring {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        vertical-align: middle;
    }
    .tracking-ring--nominal {
        border: 2px solid #1f2f88;
        background: rgba(31, 47, 136, 0.12);
    }
    .tracking-ring--buffer {
        border: 2px dashed #94a3b8;
        background: transparent;
    }

    .student-map-marker {
        position: absolute;
        transform: translate(-50%, -100%);
        display: flex;
        flex-direction: column;
        align-items: center;
        pointer-events: none;
        z-index: 1;
    }

    .student-map-marker__dot {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        border: 2px solid #fff;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
    }

    .student-map-marker__dot--inside { background: #198754; }
    .student-map-marker__dot--outside { background: #dc3545; }
    .student-map-marker__dot--violated { background: #6f42c1; }

    .student-map-marker__dot-wrap {
        position: relative;
        width: 16px;
        height: 16px;
    }

    .student-map-marker__stale-ring {
        position: absolute;
        inset: -5px;
        border: 2px solid #fd7e14;
        border-radius: 50%;
        box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.85);
        pointer-events: none;
    }

    .student-map-marker__label {
        margin-top: 4px;
        padding: 2px 6px;
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid #d7def2;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(28, 37, 86, 0.15);
        color: #1c2556;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
        max-width: 140px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .tracking-violations-list {
        max-height: 280px;
        overflow-y: auto;
    }

    .violation-card {
        border: 1px solid #e2e8ff;
        border-radius: 8px;
        padding: 0.75rem;
        margin-bottom: 0.75rem;
        background: #fafbff;
    }

    .violation-card--pending { border-left: 4px solid #dc3545; }
    .violation-card--resolved { border-left: 4px solid #6c757d; opacity: 0.85; }
    .violation-card--watch { border-left: 4px solid #fd7e14; background: #fffaf3; }

    .alerts-section-title {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #5f6791;
        margin: 0 0 0.5rem;
    }

    .violation-meta { font-size: 0.8rem; color: #5f6791; }
    .violation-actions .btn { font-size: 0.75rem; }

    @media (max-width: 767px) {
        .tracking-panel .admin-map { height: 48vh; }
        .tracking-legend {
            position: static;
            margin-top: 0.5rem;
            border-radius: 8px;
        }
    }
</style>

<?= googleMapsHelperScript() ?>

<script>
const activeGeofence = <?= json_encode($activeGeofence, JSON_UNESCAPED_UNICODE) ?>;
const campusMap = <?= json_encode($campus, JSON_UNESCAPED_UNICODE) ?>;
const mapDefaults = <?= json_encode($mapOptions, JSON_UNESCAPED_UNICODE) ?>;
const liveTrackingPollMs = <?= (int) $liveTrackingPollSeconds * 1000 ?>;

let map;
let markers = {};
let geofenceCircle = null;
let geofenceBufferCircle = null;
let StudentMarkerOverlay = null;
let initialFocusDone = false;

function isValidCoord(lat, lng) {
    return !isNaN(lat) && !isNaN(lng)
        && lat !== 0 && lng !== 0
        && Math.abs(lat) <= 90
        && Math.abs(lng) <= 180;
}

function defineStudentMarkerOverlayClass() {
    if (StudentMarkerOverlay) {
        return StudentMarkerOverlay;
    }

    function Overlay(position, name, positionStatus, gpsStale, tooltip) {
        this.position = position;
        this.name = name;
        this.positionStatus = positionStatus;
        this.gpsStale = gpsStale;
        this.tooltip = tooltip;
        this.div = null;
    }

    Overlay.prototype = Object.create(google.maps.OverlayView.prototype);
    Overlay.prototype.constructor = Overlay;

    Overlay.prototype.onAdd = function () {
        this.div = document.createElement('div');
        this.div.className = 'student-map-marker';
        this.div.title = this.tooltip || this.name;

        const dotWrap = document.createElement('div');
        dotWrap.className = 'student-map-marker__dot-wrap';

        const dot = document.createElement('div');
        dot.className = 'student-map-marker__dot student-map-marker__dot--' + this.positionStatus;

        dotWrap.appendChild(dot);

        if (this.gpsStale) {
            const ring = document.createElement('span');
            ring.className = 'student-map-marker__stale-ring';
            ring.setAttribute('aria-hidden', 'true');
            dotWrap.appendChild(ring);
        }

        const label = document.createElement('div');
        label.className = 'student-map-marker__label';
        label.textContent = this.name;

        this.div.appendChild(dotWrap);
        this.div.appendChild(label);
        this.getPanes().overlayMouseTarget.appendChild(this.div);
    };

    Overlay.prototype.draw = function () {
        if (!this.div) return;

        const projection = this.getProjection();
        if (!projection) return;

        const point = projection.fromLatLngToDivPixel(
            new google.maps.LatLng(this.position.lat, this.position.lng)
        );

        if (point) {
            this.div.style.left = point.x + 'px';
            this.div.style.top = point.y + 'px';
        }
    };

    Overlay.prototype.onRemove = function () {
        if (this.div && this.div.parentNode) {
            this.div.parentNode.removeChild(this.div);
            this.div = null;
        }
    };

    Overlay.prototype.update = function (position, name, positionStatus, gpsStale, tooltip) {
        this.position = position;
        this.name = name;
        this.positionStatus = positionStatus;
        this.gpsStale = gpsStale;
        this.tooltip = tooltip;

        if (!this.div) return;

        this.div.title = this.tooltip || name;

        const dotWrap = this.div.querySelector('.student-map-marker__dot-wrap');
        const dot = this.div.querySelector('.student-map-marker__dot');
        const label = this.div.querySelector('.student-map-marker__label');

        if (dot) dot.className = 'student-map-marker__dot student-map-marker__dot--' + positionStatus;
        if (label) label.textContent = name;

        if (dotWrap) {
            const existingRing = dotWrap.querySelector('.student-map-marker__stale-ring');
            if (gpsStale && !existingRing) {
                const ring = document.createElement('span');
                ring.className = 'student-map-marker__stale-ring';
                ring.setAttribute('aria-hidden', 'true');
                dotWrap.appendChild(ring);
            } else if (!gpsStale && existingRing) {
                existingRing.remove();
            }
        }

        this.draw();
    };

    StudentMarkerOverlay = Overlay;
    return StudentMarkerOverlay;
}

function focusMapOnTrackingArea(studentPositions = []) {
    if (!map) return;

    if (activeGeofence && isValidCoord(activeGeofence.lat, activeGeofence.lng)) {
        EpassportMaps.fitToGeofence(
            map,
            activeGeofence.lat,
            activeGeofence.lng,
            activeGeofence.radius
        );
        return;
    }

    const bounds = new google.maps.LatLngBounds();
    let hasPoints = false;

    studentPositions.forEach((position) => {
        if (isValidCoord(position.lat, position.lng)) {
            bounds.extend(position);
            hasPoints = true;
        }
    });

    if (hasPoints) {
        EpassportMaps.fitToBounds(map, bounds);
        return;
    }

    EpassportMaps.focusOpeningArea(map, campusMap);
}

function getStudentDisplayName(student) {
    const first = (student.firstname || '').trim();
    const last = (student.lastname || '').trim();
    if (first && last) return first + ' ' + last;
    return first || last || student.student_no || 'Student';
}

function getMarkerPositionStatus(student) {
    if (student.attendance_status === 'violated') {
        return 'violated';
    }

    return (parseInt(student.visual_inside, 10) === 1) ? 'inside' : 'outside';
}

function buildMarkerTooltip(student) {
    const name = getStudentDisplayName(student);
    const parts = [name];

    if (typeof student.distance_m === 'number') {
        parts.push(student.distance_m + 'm from center');
    }

    parts.push(parseInt(student.visual_inside, 10) === 1 ? 'Inside boundary' : 'Outside boundary');

    if (student.gps_stale) {
        const secs = student.seconds_since_update != null ? student.seconds_since_update + 's ago' : 'stale';
        parts.push('GPS stale (' + secs + ')');
    }

    if (parseInt(student.monitoring_inside, 10) !== parseInt(student.visual_inside, 10)) {
        parts.push(parseInt(student.monitoring_inside, 10) === 1
            ? 'Monitoring: still inside buffer'
            : 'Monitoring: outside confirmed');
    }

    return parts.join(' · ');
}

function upsertStudentMarker(student, position) {
    if (!map || !StudentMarkerOverlay) return;

    const name = getStudentDisplayName(student);
    const positionStatus = getMarkerPositionStatus(student);
    const gpsStale = !!student.gps_stale;
    const tooltip = buildMarkerTooltip(student);
    const userId = String(student.user_id);

    if (markers[userId]) {
        markers[userId].update(position, name, positionStatus, gpsStale, tooltip);
        return;
    }

    const overlay = new StudentMarkerOverlay(position, name, positionStatus, gpsStale, tooltip);
    overlay.setMap(map);
    markers[userId] = overlay;
}

function removeStaleMarkers(activeUserIds) {
    Object.keys(markers).forEach((userId) => {
        if (!activeUserIds.has(parseInt(userId, 10))) {
            markers[userId].setMap(null);
            delete markers[userId];
        }
    });
}

function drawActiveGeofence() {
    if (!map || !activeGeofence) return;

    if (geofenceCircle) geofenceCircle.setMap(null);
    if (geofenceBufferCircle) geofenceBufferCircle.setMap(null);

    const center = { lat: activeGeofence.lat, lng: activeGeofence.lng };
    const bufferM = parseInt(activeGeofence.exit_buffer_m, 10) || 10;

    geofenceCircle = new google.maps.Circle({
        map: map,
        center: center,
        radius: activeGeofence.radius,
        fillColor: '#1f2f88',
        fillOpacity: 0.12,
        strokeColor: '#1f2f88',
        strokeWeight: 2,
        clickable: false
    });

    geofenceBufferCircle = new google.maps.Circle({
        map: map,
        center: center,
        radius: activeGeofence.radius + bufferM,
        fillColor: '#94a3b8',
        fillOpacity: 0.04,
        strokeColor: '#64748b',
        strokeWeight: 2,
        strokeOpacity: 0.65,
        clickable: false
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function formatDateTime(value) {
    if (!value) return '—';
    const d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString();
}

function renderViolationCard(v) {
    const name = escapeHtml(getStudentDisplayName(v));
    const action = v.admin_action || 'none';
    const isPending = action === 'none';
    const cardClass = isPending ? 'violation-card--pending' : 'violation-card--resolved';
    const violationType = v.violation_type || 'outside_geofence';
    const violationReason = escapeHtml(v.violation_reason || 'Monitoring alert');

    let statusLine = '';
    if (violationType === 'location_off') {
        statusLine = '<strong>Location service off</strong>';
    } else if (violationType === 'no_signal') {
        statusLine = '<strong>No GPS signal 30+ min</strong> — penalty applied';
    } else if (violationType === 'no_signal_warning') {
        statusLine = '<strong>No GPS signal 15+ min</strong> — restore tracking';
    } else if (violationType === 'gps_unavailable') {
        statusLine = '<strong>GPS unavailable</strong> — waiting for coordinates';
    } else if (violationType === 'gps_active_penalized') {
        statusLine = '<strong>GPS is back on</strong> — penalty is still open. An administrator can void it.';
    } else {
        const outsideLabel = v.is_currently_outside ? 'Currently outside' : 'Returned inside';
        statusLine = outsideLabel + ' · <strong>' + escapeHtml(v.minutes_outside_label || '—') + '</strong> outside';
    }

    const smsLabel = v.sms_sent
        ? '<span class="badge text-bg-warning">SMS sent</span>'
        : (violationType === 'outside_geofence'
            ? '<span class="badge text-bg-light text-dark">SMS pending</span>'
            : (violationType === 'no_signal_warning' || violationType === 'no_signal'
                ? '<span class="badge text-bg-light text-dark">SMS pending</span>'
                : ''));

    let statusBadge = '';
    if (action === 'voided') {
        statusBadge = '<span class="badge text-bg-success">Warning voided</span>';
    } else if (action === 'penalized') {
        statusBadge = '<span class="badge text-bg-danger">Penalized</span>';
    } else if (v.attendance_status === 'violated') {
        statusBadge = '<span class="badge text-bg-danger">Violated</span>';
    }

    let actionsHtml = '';
    if (isPending && (v.can_void || v.can_penalize)) {
        const voidBtn = v.can_void
            ? `<button type="button" class="btn btn-outline-secondary btn-sm"
                    onclick="runMonitoringAction(${v.monitoring_id}, 'void_warning')">Void Warning</button>`
            : '';
        const penalizeBtn = v.can_penalize
            ? `<button type="button" class="btn btn-danger btn-sm"
                    onclick="runMonitoringAction(${v.monitoring_id}, 'absent_penalize')">Absent & Penalize</button>`
            : '';
        actionsHtml = `<div class="violation-actions d-flex flex-wrap gap-1 mt-2">${voidBtn}${penalizeBtn}</div>`;
    } else if (v.can_void_penalty) {
        const restoreLabel = v.restore_status === 'late' ? 'late' : 'present';
        actionsHtml = `<div class="violation-actions d-flex flex-wrap gap-1 mt-2">
            <button type="button" class="btn btn-outline-success btn-sm"
                    onclick="runMonitoringAction(${v.monitoring_id}, 'void_penalty', '${restoreLabel}')">Void Penalty</button>
        </div>`;
    }

    return `
        <div class="violation-card ${cardClass}">
            <div class="fw-semibold">${name}</div>
            <div class="violation-meta mt-1">${statusLine}</div>
            <div class="violation-meta">${violationReason}</div>
            <div class="d-flex flex-wrap gap-1 mt-2">${smsLabel}${statusBadge}</div>
            ${v.warning_sent_at ? `<div class="violation-meta mt-1">SMS at ${formatDateTime(v.warning_sent_at)}</div>` : ''}
            ${actionsHtml}
        </div>
    `;
}

function renderWatchCard(w) {
    const name = escapeHtml(getStudentDisplayName(w));
    const reason = escapeHtml(w.watch_reason || 'Outside geofence — awaiting confirmation.');
    const distance = typeof w.distance_m === 'number'
        ? `<div class="violation-meta mt-1">${w.distance_m}m from geofence center</div>`
        : '';

    return `
        <div class="violation-card violation-card--watch">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="fw-semibold">${name}</div>
                <span class="badge text-bg-warning">Watch</span>
            </div>
            <div class="violation-meta mt-1">${reason}</div>
            ${distance}
        </div>
    `;
}

function renderLocationJumpCard(jump) {
    const name = escapeHtml(getStudentDisplayName(jump));
    const meters = jump.distance_m != null ? Math.round(jump.distance_m) + ' m' : '';
    const seconds = jump.elapsed_seconds != null ? jump.elapsed_seconds + ' s' : '';
    const detail = [meters, seconds].filter(Boolean).join(' in ');
    const stamp = escapeHtml(jump.attendance_status || '');

    return `
        <div class="violation-card violation-card--watch">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="fw-semibold">${name}</div>
                <span class="badge text-bg-warning">Location jumped</span>
            </div>
            <div class="violation-meta mt-1">The phone moved faster than a person can. Attendance is still <strong>${stamp}</strong>.</div>
            ${detail ? `<div class="violation-meta">${escapeHtml(detail)} from the last accepted point.</div>` : ''}
            <div class="violation-actions d-flex flex-wrap gap-1 mt-2">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                    onclick="reviewLocationJump(${parseInt(jump.attendance_id, 10) || 0})">Reviewed</button>
            </div>
        </div>
    `;
}

function renderAlerts(watch, violations, locationJumps) {
    locationJumps = locationJumps || [];
    const container = document.getElementById('violationsList');
    const violationBadge = document.getElementById('violationCount');
    const watchBadge = document.getElementById('watchCount');
    const pendingViolations = violations.filter(v => (v.admin_action || 'none') === 'none');

    violationBadge.textContent = String(pendingViolations.length);
    violationBadge.className = 'badge ' + (pendingViolations.length ? 'text-bg-danger' : 'text-bg-secondary');

    if (watchBadge) {
        if (watch.length) {
            watchBadge.textContent = watch.length + ' watch';
            watchBadge.classList.remove('d-none');
        } else {
            watchBadge.classList.add('d-none');
        }
    }

    if (!watch.length && !violations.length && !locationJumps.length) {
        container.innerHTML = '<div class="text-muted small py-3 text-center">No active alerts or violations.</div>';
        return;
    }

    let html = '';

    if (locationJumps.length) {
        html += '<p class="alerts-section-title">Location jumped</p>';
        html += locationJumps.map(renderLocationJumpCard).join('');
    }

    if (watch.length) {
        html += '<p class="alerts-section-title' + (locationJumps.length ? ' mt-3' : '') + '">Watch — unconfirmed outside</p>';
        html += watch.map(renderWatchCard).join('');
    }

    if (violations.length) {
        html += (watch.length || locationJumps.length) ? '<p class="alerts-section-title mt-3">Confirmed violations</p>' : '';
        html += violations.map(renderViolationCard).join('');
    }

    container.innerHTML = html;
}

function reviewLocationJump(attendanceId) {
    if (!attendanceId) return;
    epassportConfirm('Mark this location jump as reviewed? The attendance stamp will stay as it is.', {
        title: 'Review location jump',
        confirmLabel: 'Mark reviewed'
    }).then(function (ok) {
        if (!ok) return;

        fetch('../ajax/monitoring_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'review_location_jump',
                attendance_id: String(attendanceId)
            }).toString()
        })
        .then(res => res.json())
        .then(data => {
            epassportAlert(data.message || (data.success ? 'Marked as reviewed.' : 'Action failed.'), data.success ? 'Reviewed' : 'Action failed');
            if (data.success) loadStudentLocations();
        })
        .catch(() => epassportAlert('Could not complete that action. Please try again.', 'Action failed'));
    });
}

function runMonitoringAction(monitoringId, action, restoreStatus) {
    const labels = {
        void_warning: 'void this warning',
        void_penalty: 'void this penalty and restore the student to ' + (restoreStatus || 'present'),
        absent_penalize: 'mark this student absent and apply penalty',
    };
    const label = labels[action] || 'continue';
    const titles = {
        void_warning: 'Void warning',
        void_penalty: 'Void penalty',
        absent_penalize: 'Absent and penalize',
    };
    epassportConfirm('Are you sure you want to ' + label + '?', {
        title: titles[action] || 'Please confirm',
        confirmLabel: action === 'absent_penalize' ? 'Apply penalty' : 'Confirm',
        danger: action === 'absent_penalize' || action === 'void_penalty'
    }).then(function (ok) {
        if (!ok) return;

        fetch('../ajax/monitoring_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ monitoring_id: monitoringId, action: action }).toString()
        })
        .then(res => res.json())
        .then(data => {
            epassportAlert(data.message || (data.success ? 'Done.' : 'Action failed.'), data.success ? 'Done' : 'Action failed');
            if (data.success) loadStudentLocations();
        })
        .catch(() => epassportAlert('Could not complete that action. Please try again.', 'Action failed'));
    });
}

function bootLiveTrackingMap() {
    const mapEl = document.getElementById('liveTrackingMap');
    if (!mapEl) {
        console.error('Live tracking map container #liveTrackingMap was not found.');
        return;
    }

    if (typeof google === 'undefined' || !google.maps) {
        console.error('Google Maps API is not available.');
        return;
    }

    if (typeof EpassportMaps === 'undefined') {
        console.error('EpassportMaps helper failed to load.');
        return;
    }

    defineStudentMarkerOverlayClass();

    map = new google.maps.Map(
        mapEl,
        EpassportMaps.createOptions(mapDefaults)
    );

    drawActiveGeofence();
    focusMapOnTrackingArea([]);

    EpassportMaps.applyMaxZoomAt(
        map,
        new google.maps.LatLng(campusMap.latitude, campusMap.longitude)
    );

    google.maps.event.addListenerOnce(map, 'idle', function () {
        google.maps.event.trigger(map, 'resize');
        if (!initialFocusDone) {
            focusMapOnTrackingArea([]);
        }
    });

    loadStudentLocations();
    setInterval(loadStudentLocations, liveTrackingPollMs);
}

function loadStudentLocations() {
    if (!map) return;

    fetch('../ajax/get_live_locations.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('violationsList').innerHTML =
                    '<div class="text-danger small py-3 text-center">' + escapeHtml(data.message || 'Failed to load data.') + '</div>';
                return;
            }

            const activeUserIds = new Set();
            const positions = [];
            const studentList = data.students || [];

            document.getElementById('trackedStudentCount').innerHTML =
                '<strong>' + studentList.length + '</strong> on map';

            studentList.forEach((student) => {
                const position = {
                    lat: parseFloat(student.latitude),
                    lng: parseFloat(student.longitude)
                };

                if (!isValidCoord(position.lat, position.lng)) return;

                positions.push(position);
                activeUserIds.add(parseInt(student.user_id, 10));
                upsertStudentMarker(student, position);
            });

            removeStaleMarkers(activeUserIds);

            if (!initialFocusDone) {
                focusMapOnTrackingArea(positions);
                initialFocusDone = true;
            }

            renderAlerts(data.watch || [], data.violations || [], data.location_jumps || []);
        })
        .catch(() => {
            document.getElementById('violationsList').innerHTML =
                '<div class="text-danger small py-3 text-center">Could not reach monitoring service.</div>';
        });
}

window.initLiveTrackingMap = function () {
    if (window.__liveTrackingMapBooted) {
        return;
    }

    window.__liveTrackingMapBooted = true;
    bootLiveTrackingMap();
};

window.addEventListener('load', function () {
    window.setTimeout(function () {
        if (window.__liveTrackingMapBooted) {
            return;
        }
        if (typeof google !== 'undefined' && google.maps && typeof EpassportMaps !== 'undefined') {
            console.warn('Live tracking map: invoking initLiveTrackingMap fallback.');
            window.initLiveTrackingMap();
        }
    }, 2500);
});
</script>

<?= googleMapsHelperScript() ?>
<?= googleMapsApiScript('initLiveTrackingMap') ?>

<?php renderAdminFooter(); ?>
