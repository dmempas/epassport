/**
 * Student GPS flow — location prompt only after officer QR scan.
 */

/**
 * Moving average GPS filter — smooths the last N readings before server evaluation.
 */
class GPSMovingAverageFilter {
    constructor(historySize = 5) {
        this.historySize = historySize;
        this.history = [];
    }

    addReading(lat, lon, accuracy) {
        this.history.push({ lat, lon, accuracy });

        if (this.history.length > this.historySize) {
            this.history.shift();
        }
    }

    getSmoothed() {
        if (this.history.length === 0) {
            return null;
        }

        const avgLat = this.history.reduce((sum, reading) => sum + reading.lat, 0) / this.history.length;
        const avgLon = this.history.reduce((sum, reading) => sum + reading.lon, 0) / this.history.length;
        const avgAcc = this.history.reduce((sum, reading) => sum + reading.accuracy, 0) / this.history.length;

        return {
            latitude: avgLat,
            longitude: avgLon,
            accuracy: avgAcc,
            readingCount: this.history.length,
        };
    }

    reset() {
        this.history = [];
    }
}

let gpsFilter = new GPSMovingAverageFilter(5);

function getFilteredCoordinates(position) {
    gpsFilter.addReading(
        position.coords.latitude,
        position.coords.longitude,
        position.coords.accuracy
    );

    const filtered = gpsFilter.getSmoothed();

    return {
        latitude: filtered ? filtered.latitude : position.coords.latitude,
        longitude: filtered ? filtered.longitude : position.coords.longitude,
        accuracy: filtered ? filtered.accuracy : position.coords.accuracy,
    };
}

let currentAttendanceId = null;
let gpsActive = false;
let locationFlowAttendanceId = null;
let locationWatchStop = null;
let locationCountdownTimer = null;
let locationWaitSecondsRemaining = 0;
let locationWaitActive = false;
let locationSubmitting = false;
let monitoringAttendanceId = null;
let monitoringWatchStop = null;
let lastMonitoringSendAt = 0;
let lastSuccessfulMonitoringAt = 0;
let monitoringHeartbeatTimer = null;
let monitoringCaptureInFlight = false;
let promptedLogoutId = null;
const LOCATION_WAIT_SECONDS = 300;
const MONITORING_POLL_MS = 15000;
const MONITORING_MIN_SEND_MS = 10000;
const LOCATION_OFF_GRACE_MS = 90000;
const LOCATION_OFF_HEARTBEAT_MS = 30000;

function openBackgroundMonitorPage() {
    if (typeof BackgroundMonitor !== 'undefined') {
        BackgroundMonitor.openMonitorPage();
        return;
    }

    window.location.href = 'monitor.php';
}

function canSendMonitoringUpdate() {
    if (typeof BackgroundMonitor === 'undefined') {
        return true;
    }

    return BackgroundMonitor.shouldSendGps();
}

function activateBackgroundMonitor(attendanceId, eventName) {
    if (typeof BackgroundMonitor === 'undefined') {
        return;
    }

    BackgroundMonitor.start({
        attendanceId: attendanceId,
        eventName: eventName || '',
    });
}

function deactivateBackgroundMonitor() {
    if (typeof BackgroundMonitor !== 'undefined') {
        BackgroundMonitor.stop();
    }
}

function clearLocationWaitState() {
    locationWaitActive = false;

    if (locationWatchStop) {
        locationWatchStop();
        locationWatchStop = null;
    }

    if (locationCountdownTimer) {
        clearInterval(locationCountdownTimer);
        locationCountdownTimer = null;
    }

    locationWaitSecondsRemaining = 0;
}

function formatCountdown(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return mins + ':' + String(secs).padStart(2, '0');
}

function ensureLocationModal() {
    let modal = document.getElementById('locationRequestModal');

    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'locationRequestModal';
        modal.className = 'modal fade';
        modal.setAttribute('tabindex', '-1');
        modal.innerHTML =
            '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content">' +
            '<div class="modal-header bg-primary text-white">' +
            '<h5 class="modal-title" id="locationModalTitle">Location verification required</h5>' +
            '</div>' +
            '<div class="modal-body" id="locationModalBody"></div>' +
            '<div class="modal-footer flex-column flex-sm-row gap-2" id="locationModalFooter"></div>' +
            '</div></div>';
        document.body.appendChild(modal);
    }

    return modal;
}

function setLocationModalTitle(text) {
    const title = document.getElementById('locationModalTitle');
    if (title) {
        title.textContent = text;
    }
}

function updateLocationWaitStatus(message) {
    const el = document.getElementById('locationWaitStatus');
    if (el && message) {
        el.textContent = message;
    }
}

function renderLocationModalPrompt(attendanceId) {
    const body = document.getElementById('locationModalBody');
    const footer = document.getElementById('locationModalFooter');

    if (!body || !footer) {
        return;
    }

    setLocationModalTitle('Location verification required');

    const insecureWarning = LocationBridge.isSecure()
        ? ''
        : '<div class="alert alert-danger small py-2">GPS is blocked on <strong>HTTP</strong>. ' +
          'Tap <strong>Allow</strong> below — you will be sent to the <strong>HTTPS</strong> version first.</div>';

    body.innerHTML =
        insecureWarning +
        '<p class="mb-2">An officer scanned your QR code. Share your <strong>current GPS location</strong> to verify campus presence.</p>' +
        '<p class="small text-muted mb-0">Choose <strong>Allow</strong> to share location, or <strong>Deny</strong> to record absent with penalty.</p>';

    footer.innerHTML =
        '<button type="button" class="btn btn-success w-100" id="allowLocationBtn">Allow Location Access</button>' +
        '<button type="button" class="btn btn-outline-danger w-100" id="denyLocationBtn">Deny (mark absent)</button>';

    document.getElementById('allowLocationBtn').onclick = function () {
        onAllowLocationClicked(attendanceId);
    };

    document.getElementById('denyLocationBtn').onclick = function () {
        clearLocationWaitState();
        hideLocationModal();
        sendExplicitDenied(attendanceId);
    };
}

function bindWaitingModalActions(attendanceId) {
    const denyBtn = document.getElementById('denyLocationBtn');
    if (denyBtn) {
        denyBtn.onclick = function () {
            clearLocationWaitState();
            hideLocationModal();
            sendExplicitDenied(attendanceId);
        };
    }

    const retryBtn = document.getElementById('retryGpsBtn');
    if (retryBtn) {
        retryBtn.onclick = function () {
            retryGpsCapture(attendanceId);
        };
    }

    const httpsBtn = document.getElementById('openHttpsBtn');
    if (httpsBtn) {
        httpsBtn.onclick = function () {
            window.location.href = LocationBridge.getHttpsUrl();
        };
    }

    const permHelpBtn = document.getElementById('showPermissionHelpBtn');
    if (permHelpBtn) {
        permHelpBtn.onclick = function () {
            const help = document.getElementById('locationPermissionHelp');
            if (help) {
                help.style.display = 'block';
                help.innerHTML = LocationBridge.getPermissionHelpHtml();
            }
        };
    }
}

function showPermissionBlockedHelp(message) {
    const help = document.getElementById('locationPermissionHelp');
    if (help) {
        help.style.display = 'block';
        help.innerHTML =
            '<p class="small text-danger mb-2">' + (message || '') + '</p>' +
            LocationBridge.getPermissionHelpHtml();
    }
}

function renderLocationModalWaiting(attendanceId) {
    const body = document.getElementById('locationModalBody');
    const footer = document.getElementById('locationModalFooter');

    if (!body || !footer) {
        return;
    }

    setLocationModalTitle('Turn on location services');

    const settingsHint = 'Allow <strong>Location</strong> for this site in your browser, and turn on device GPS.';

    const insecureBlock = LocationBridge.isSecure()
        ? ''
        : '<div class="alert alert-danger small py-2 mb-2">' +
          '<strong>GPS cannot work on HTTP.</strong> Tap <strong>Use secure HTTPS link</strong> below, ' +
          'accept the certificate warning once, then allow location.</div>';

    body.innerHTML =
        insecureBlock +
        '<p class="mb-2" id="locationWaitTitle">Waiting for location services…</p>' +
        '<p class="small text-muted mb-2" id="locationWaitHint">' + settingsHint + '</p>' +
        '<div id="locationPermissionHelp" class="alert alert-warning small py-2" style="display:none;"></div>' +
        '<p class="small text-info mb-3" id="locationWaitStatus">Listening for GPS…</p>' +
        '<div class="text-center mb-2" id="locationCountdownWrap">' +
        '<div class="display-6 fw-bold text-primary" id="locationCountdown">' +
        formatCountdown(locationWaitSecondsRemaining) +
        '</div>' +
        '<div class="small text-muted">Time remaining</div>' +
        '</div>' +
        '<div class="progress" id="locationCountdownProgress" style="height: 8px;">' +
        '<div class="progress-bar progress-bar-striped progress-bar-animated" id="locationCountdownBar" role="progressbar" style="width: 100%"></div>' +
        '</div>';

    footer.innerHTML =
        (!LocationBridge.isSecure()
            ? '<button type="button" class="btn btn-danger w-100" id="openHttpsBtn">Use secure HTTPS link</button>'
            : '') +
        '<button type="button" class="btn btn-outline-primary w-100" id="showPermissionHelpBtn">Fix browser permission</button>' +
        '<button type="button" class="btn btn-primary w-100" id="retryGpsBtn">Retry GPS</button>' +
        '<button type="button" class="btn btn-outline-danger w-100" id="denyLocationBtn">Deny (mark absent)</button>';

    bindWaitingModalActions(attendanceId);
}

function startGpsListening(attendanceId) {
    if (locationWatchStop) {
        locationWatchStop();
    }

    if (!LocationBridge.isSecure()) {
        updateLocationWaitStatus('Switch to HTTPS before GPS can work.');
        return;
    }

    locationWatchStop = LocationBridge.watchLocationEnabled(
        function (position) {
            onLocationServicesEnabled(attendanceId, position);
        },
        {
            pollMs: 1000,
            onStatus: updateLocationWaitStatus,
        }
    );
}

function retryGpsCapture(attendanceId) {
    if (!LocationBridge.isSecure()) {
        window.location.href = LocationBridge.getHttpsUrl();
        return;
    }

    updateLocationWaitStatus('Retrying GPS…');

    LocationBridge.captureNow(
        function (position) {
            onLocationServicesEnabled(attendanceId, position);
        },
        function (statusMsg) {
            updateLocationWaitStatus(statusMsg);
            if (statusMsg && statusMsg.indexOf('blocked') >= 0) {
                showPermissionBlockedHelp(statusMsg);
            }
        }
    );

    startGpsListening(attendanceId);
}

function showLocationAcquiringUI() {
    const title = document.getElementById('locationWaitTitle');
    const hint = document.getElementById('locationWaitHint');
    const status = document.getElementById('locationWaitStatus');
    const countdownWrap = document.getElementById('locationCountdownWrap');
    const progress = document.getElementById('locationCountdownProgress');

    setLocationModalTitle('Verifying location');

    if (title) {
        title.textContent = 'GPS signal received';
    }

    if (hint) {
        hint.innerHTML = 'Checking your coordinates against the campus geofence boundary.';
    }

    if (status) {
        status.textContent = 'Please wait…';
    }

    if (countdownWrap) {
        countdownWrap.style.display = 'none';
    }

    if (progress) {
        progress.style.display = 'none';
    }
}

function showAttendanceResultModal(type, data) {
    const modal = ensureLocationModal();
    const body = document.getElementById('locationModalBody');
    const footer = document.getElementById('locationModalFooter');

    if (!body || !footer) {
        return;
    }

    if (type === 'present') {
        setLocationModalTitle('Attendance marked present');

        body.innerHTML =
            '<div class="text-center mb-3">' +
            '<div class="display-1 text-success">✓</div>' +
            '</div>' +
            '<p class="mb-2"><strong>Your attendance has been marked present successfully.</strong></p>' +
            '<p class="small mb-2">You are inside the campus boundary' +
            (typeof data.distance === 'number'
                ? ' (' + data.distance + ' m from center, limit ' + data.geofence_radius + ' m).'
                : '.') +
            '</p>' +
            '<div class="alert alert-success small mb-2">' +
            '<strong>Live GPS tracking starts automatically.</strong> ' +
            'Keep Location Services and Wi-Fi/mobile data ON. Your position updates about every 15 seconds while this page stays open.' +
            '</div>' +
            '<div class="alert alert-warning small mb-0">' +
            'Do not turn off GPS. Leaving the campus boundary for 30+ minutes triggers an SMS warning.' +
            '</div>';

        footer.innerHTML =
            '<button type="button" class="btn btn-success w-100" id="closePresentModalBtn">Start Background Monitoring</button>';

        document.getElementById('closePresentModalBtn').onclick = function () {
            hideLocationModal();
            openBackgroundMonitorPage();
        };

        setTimeout(function () {
            if (document.getElementById('closePresentModalBtn')) {
                openBackgroundMonitorPage();
            }
        }, 2500);
    } else if (type === 'late') {
        setLocationModalTitle('Attendance marked late (rescan)');

        body.innerHTML =
            '<div class="text-center mb-3">' +
            '<div class="display-1 text-warning">⏱</div>' +
            '</div>' +
            '<p class="mb-2"><strong>Rescan accepted.</strong> You are inside the campus boundary and marked <strong>late</strong>.</p>' +
            '<div class="alert alert-success small mb-2">' +
            '<strong>Live GPS tracking starts automatically.</strong> ' +
            'Keep Location Services and Wi-Fi/mobile data ON. Your position updates as you move.' +
            '</div>' +
            '<div class="alert alert-warning small mb-0">' +
            'Do not turn off GPS during the event.' +
            '</div>';

        footer.innerHTML =
            '<button type="button" class="btn btn-warning w-100" id="closeLateModalBtn">Start Background Monitoring</button>';

        document.getElementById('closeLateModalBtn').onclick = function () {
            hideLocationModal();
            openBackgroundMonitorPage();
        };

        setTimeout(function () {
            if (document.getElementById('closeLateModalBtn')) {
                openBackgroundMonitorPage();
            }
        }, 2500);
    } else if (type === 'absent_outside') {
        setLocationModalTitle('Attendance marked absent');

        body.innerHTML =
            '<div class="text-center mb-3">' +
            '<div class="display-1 text-danger">✕</div>' +
            '</div>' +
            '<p class="mb-2"><strong>You are outside the campus boundary.</strong> Attendance has been marked absent.</p>' +
            '<p class="small text-muted mb-0">Distance: <strong>' +
            (data.distance || '—') +
            ' m</strong> (allowed radius: <strong>' +
            (data.geofence_radius || '—') +
            ' m</strong>). A penalty has been applied per event rules.</p>';

        footer.innerHTML =
            '<button type="button" class="btn btn-secondary w-100" id="closeAbsentModalBtn">Close</button>';

        document.getElementById('closeAbsentModalBtn').onclick = function () {
            hideLocationModal();
        };
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modal, {
            backdrop: 'static',
            keyboard: false,
        }).show();
    }
}

function updateLocationCountdownUI() {
    if (!locationWaitActive) {
        return;
    }

    const countdownEl = document.getElementById('locationCountdown');
    const barEl = document.getElementById('locationCountdownBar');

    if (countdownEl) {
        countdownEl.textContent = formatCountdown(locationWaitSecondsRemaining);
    }

    if (barEl) {
        const pct = Math.max(
            0,
            Math.round((locationWaitSecondsRemaining / LOCATION_WAIT_SECONDS) * 100)
        );
        barEl.style.width = pct + '%';
    }
}

function onLocationServicesEnabled(attendanceId, position) {
    if (locationSubmitting) {
        return;
    }

    if (!LocationBridge.isValidPosition(position)) {
        return;
    }

    locationWaitActive = false;

    if (locationCountdownTimer) {
        clearInterval(locationCountdownTimer);
        locationCountdownTimer = null;
    }

    if (locationWatchStop) {
        locationWatchStop();
        locationWatchStop = null;
    }

    showLocationAcquiringUI();
    updateGPSUI('info', 'GPS received. Verifying your position against the campus boundary…');

    locationSubmitting = true;

    sendLocationUpdate(attendanceId, position).finally(function () {
        locationSubmitting = false;
    });
}

function onLocationWaitExpired(attendanceId) {
    if (!locationWaitActive || locationSubmitting) {
        return;
    }

    clearLocationWaitState();

    updateGPSUI(
        'warning',
        'Location was not enabled in time. Tap <strong>Allow Location Access</strong> to try again, or Deny to record absent.'
    );

    showLocationRequestModal(attendanceId, 'prompt');
}

function startLocationCountdown(attendanceId) {
    locationCountdownTimer = setInterval(function () {
        if (!locationWaitActive || locationSubmitting) {
            return;
        }

        locationWaitSecondsRemaining -= 1;
        updateLocationCountdownUI();

        if (locationWaitSecondsRemaining <= 0) {
            onLocationWaitExpired(attendanceId);
        }
    }, 1000);
}

function beginLocationWaitFlow(attendanceId) {
    clearLocationWaitState();
    locationWaitActive = true;
    locationWaitSecondsRemaining = LOCATION_WAIT_SECONDS;

    renderLocationModalWaiting(attendanceId);
    updateLocationCountdownUI();

    const modal = ensureLocationModal();
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modal, {
            backdrop: 'static',
            keyboard: false,
        }).show();
    }

    updateGPSUI(
        'info',
        'Turn on device GPS within ' + LOCATION_WAIT_SECONDS + ' seconds. We are listening for your location…'
    );

    locationWatchStop = LocationBridge.watchLocationEnabled(
        function (position) {
            onLocationServicesEnabled(attendanceId, position);
        },
        {
            pollMs: 1000,
            onStatus: updateLocationWaitStatus,
        }
    );

    startLocationCountdown(attendanceId);
}

function onAllowLocationClicked(attendanceId) {
    if (!LocationBridge.isSecure()) {
        window.location.href = LocationBridge.getHttpsUrl();
        return;
    }

    clearLocationWaitState();
    locationWaitActive = true;
    locationWaitSecondsRemaining = LOCATION_WAIT_SECONDS;

    renderLocationModalWaiting(attendanceId);
    updateLocationCountdownUI();

    const modal = ensureLocationModal();
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modal, {
            backdrop: 'static',
            keyboard: false,
        }).show();
    }

    updateGPSUI('info', 'Allow location when your browser asks, then turn on device GPS if needed.');

    LocationBridge.ensurePermission(
        function (position) {
            if (position) {
                onLocationServicesEnabled(attendanceId, position);
                return;
            }
            startGpsListening(attendanceId);
        },
        function (block) {
            showPermissionBlockedHelp(block && block.message ? block.message : '');
            updateLocationWaitStatus(block && block.message ? block.message : 'Permission required.');
            startGpsListening(attendanceId);
        }
    );

    if (locationCountdownTimer) {
        clearInterval(locationCountdownTimer);
    }

    startLocationCountdown(attendanceId);
}

function showLocationRequestModal(attendanceId, mode) {
    const modal = ensureLocationModal();
    const viewMode = mode || 'prompt';

    if (viewMode === 'waiting') {
        renderLocationModalWaiting(attendanceId);
    } else {
        renderLocationModalPrompt(attendanceId);
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modal, {
            backdrop: 'static',
            keyboard: false,
        }).show();
    } else {
        modal.style.display = 'block';
        modal.classList.add('show');
    }

    if (viewMode === 'prompt') {
        updateGPSUI(
            'warning',
            '<strong>Officer scan detected.</strong> Allow or deny location access below.'
        );
    }
}

function hideLocationModal() {
    const modal = document.getElementById('locationRequestModal');

    if (!modal) {
        return;
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const instance = bootstrap.Modal.getInstance(modal);
        if (instance) {
            instance.hide();
        }
    } else {
        modal.style.display = 'none';
        modal.classList.remove('show');
    }

    document.querySelectorAll('.modal-backdrop').forEach(function (el) {
        el.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('overflow');
}

function handleLocationRequest(attendanceId, meta) {
    const id = parseInt(attendanceId, 10);

    if (!id) {
        return;
    }

    if (monitoringAttendanceId && monitoringAttendanceId !== id) {
        stopContinuousMonitoring();
    }

    const modal = document.getElementById('locationRequestModal');
    const modalVisible = modal && modal.classList.contains('show');

    if (
        locationFlowAttendanceId === id &&
        gpsActive &&
        modalVisible &&
        (locationWaitActive || locationSubmitting)
    ) {
        return;
    }

    if (locationFlowAttendanceId === id && gpsActive && modalVisible && !locationWaitActive) {
        return;
    }

    locationFlowAttendanceId = id;
    currentAttendanceId = id;
    gpsActive = true;
    gpsFilter.reset();

    if (meta && meta.realtime_event_id) {
        fetch('../ajax/ack_location_prompt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: 'realtime_event_id=' + encodeURIComponent(String(meta.realtime_event_id)),
        }).catch(function () {});
    }

    showLocationRequestModal(id, 'prompt');
}

async function sendLocationUpdate(attendanceId, position) {
    try {
        const filteredCoords = getFilteredCoordinates(position);
        const body = new URLSearchParams();
        body.set('attendance_id', String(attendanceId));
        body.set('lat', String(filteredCoords.latitude));
        body.set('lng', String(filteredCoords.longitude));
        body.set('accuracy', String(filteredCoords.accuracy));

        const response = await fetch('../ajax/update_location.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'same-origin',
            body: body.toString(),
        });

        const data = await response.json();

        console.log('GPS UPDATE:', data);

        if (!data.success) {
            hideLocationModal();
            updateGPSUI('danger', data.message || 'GPS update failed.');
            showLocationRequestModal(attendanceId, 'prompt');
            return;
        }

        if (data.attendance_status === 'pending') {
            updateGPSUI('info', data.message || 'Processing your location…');
            return;
        }

        hideLocationModal();

        if (data.attendance_status === 'present' || data.attendance_status === 'late') {
            const statusLabel = data.attendance_status === 'late' ? 'late' : 'present';
            updateGPSUI(
                'success',
                'Attendance: <strong>' + statusLabel + '</strong> — inside campus boundary. Opening background monitoring…'
            );
            if (data.attendance_status === 'late') {
                showAttendanceResultModal('late', data);
            } else {
                showAttendanceResultModal('present', data);
            }
            startContinuousMonitoring(attendanceId, data.event_name || '');
            gpsActive = false;
            locationFlowAttendanceId = null;
            return;
        }

        if (data.attendance_status === 'absent' && data.location_status === 'outside') {
            updateGPSUI(
                'danger',
                'Attendance: <strong>absent</strong> — outside campus boundary. Penalty applied.'
            );
            showAttendanceResultModal('absent_outside', data);
        } else {
            updateGPSUI(
                'danger',
                'Attendance: <strong>absent</strong>.'
            );
        }

        stopGPSMonitoring();
        locationFlowAttendanceId = null;
    } catch (err) {
        console.error('GPS SEND ERROR:', err);
        hideLocationModal();
        updateGPSUI('danger', 'Failed to send GPS. Check your connection and try again.');
        showLocationRequestModal(attendanceId, 'prompt');
    }
}

async function postMonitoringUpdate(attendanceId, payload) {
    const body = new URLSearchParams();
    body.set('attendance_id', String(attendanceId));
    body.set('monitoring', '1');

    Object.keys(payload || {}).forEach(function (key) {
        body.set(key, String(payload[key]));
    });

    const response = await fetch('../ajax/update_location.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        credentials: 'same-origin',
        body: body.toString(),
    });

    return response.json();
}

function handleMonitoringViolationResponse(data) {
    if (!data || !data.violation) {
        return;
    }

    stopContinuousMonitoring();
    updateGPSUI(
        'danger',
        'Location services are off. Attendance marked <strong>violated</strong> with penalty.'
    );
}

async function sendMonitoringCoordinates(attendanceId, position) {
    if (!canSendMonitoringUpdate()) {
        return null;
    }
    if (!attendanceId || !position || !position.coords) {
        return;
    }

    const now = Date.now();
    if (now - lastMonitoringSendAt < MONITORING_MIN_SEND_MS) {
        return;
    }

    lastMonitoringSendAt = now;

    try {
        const filteredCoords = getFilteredCoordinates(position);
        const data = await postMonitoringUpdate(attendanceId, {
            lat: filteredCoords.latitude,
            lng: filteredCoords.longitude,
            accuracy: filteredCoords.accuracy,
        });

        if (data && data.success) {
            lastSuccessfulMonitoringAt = Date.now();
        }

        handleMonitoringViolationResponse(data);

        if (data && data.monitoring && data.location_status === 'outside') {
            updateGPSUI(
                'danger',
                'You are <strong>outside</strong> the campus boundary. Return immediately to avoid penalties.'
            );
        } else if (data && data.monitoring && data.location_status === 'inside') {
            updateGPSUI(
                'success',
                'Live tracking active — you are inside the campus boundary.'
            );
        }
    } catch (e) {
        console.warn('Monitoring coordinate update failed:', e);
    }
}

function canReportLocationOff() {
    if (!lastSuccessfulMonitoringAt) {
        return false;
    }

    return Date.now() - lastSuccessfulMonitoringAt >= LOCATION_OFF_GRACE_MS;
}

function captureMonitoringPositionOnce() {
    return new Promise(function (resolve) {
        if (typeof LocationBridge !== 'undefined' && LocationBridge.isSecure()) {
            LocationBridge.captureNow(
                function (position) {
                    resolve({ ok: true, position: position });
                },
                null,
                function () {
                    resolve({ ok: false });
                }
            );
            return;
        }

        if (!navigator.geolocation) {
            resolve({ ok: false });
            return;
        }

        navigator.geolocation.getCurrentPosition(
            function (position) {
                resolve({ ok: true, position: position });
            },
            function () {
                resolve({ ok: false });
            },
            {
                enableHighAccuracy: true,
                timeout: 25000,
                maximumAge: 0,
            }
        );
    });
}

async function runMonitoringHeartbeat(attendanceId) {
    if (!attendanceId || monitoringCaptureInFlight || !canSendMonitoringUpdate()) {
        return;
    }

    if (!canReportLocationOff()) {
        return;
    }

    monitoringCaptureInFlight = true;

    try {
        const result = await captureMonitoringPositionOnce();

        if (result.ok && result.position) {
            lastMonitoringSendAt = 0;
            await sendMonitoringCoordinates(attendanceId, result.position);
            return;
        }

        if (!canReportLocationOff()) {
            return;
        }

        const data = await postMonitoringUpdate(attendanceId, { location_off: '1' });
        handleMonitoringViolationResponse(data);

        if (!data || !data.violation) {
            updateGPSUI(
                'warning',
                'GPS signal lost — keep location services ON and this page open.'
            );
        }
    } catch (e) {
        console.warn('Monitoring heartbeat failed:', e);
    } finally {
        monitoringCaptureInFlight = false;
    }
}

async function sendLocationOffPing(attendanceId) {
    if (!canReportLocationOff()) {
        return;
    }

    try {
        const data = await postMonitoringUpdate(attendanceId, { location_off: '1' });
        handleMonitoringViolationResponse(data);
    } catch (e) {
        console.warn('Location-off ping failed:', e);
    }
}

function startContinuousMonitoring(attendanceId, eventName) {
    stopContinuousMonitoring();
    monitoringAttendanceId = attendanceId;
    gpsFilter.reset();
    lastMonitoringSendAt = 0;
    lastSuccessfulMonitoringAt = Date.now();

    activateBackgroundMonitor(attendanceId, eventName || '');

    captureMonitoringPositionOnce().then(function (result) {
        if (result.ok && result.position && monitoringAttendanceId === attendanceId) {
            lastMonitoringSendAt = 0;
            sendMonitoringCoordinates(attendanceId, result.position);
        }
    });

    if (typeof LocationBridge !== 'undefined' && LocationBridge.watchContinuousPosition) {
        monitoringWatchStop = LocationBridge.watchContinuousPosition(
            function (position) {
                if (monitoringAttendanceId) {
                    sendMonitoringCoordinates(monitoringAttendanceId, position);
                }
            },
            function () {
                // watchPosition handles movement; heartbeat handles prolonged loss.
            },
            {
                pollMs: MONITORING_POLL_MS,
                minIntervalMs: MONITORING_MIN_SEND_MS,
            }
        );
    }

    monitoringHeartbeatTimer = setInterval(function () {
        if (monitoringAttendanceId) {
            runMonitoringHeartbeat(monitoringAttendanceId);
        }
    }, LOCATION_OFF_HEARTBEAT_MS);
}

function stopContinuousMonitoring() {
    monitoringAttendanceId = null;
    lastMonitoringSendAt = 0;
    lastSuccessfulMonitoringAt = 0;
    monitoringCaptureInFlight = false;
    deactivateBackgroundMonitor();

    if (monitoringWatchStop) {
        monitoringWatchStop();
        monitoringWatchStop = null;
    }

    if (monitoringHeartbeatTimer) {
        clearInterval(monitoringHeartbeatTimer);
        monitoringHeartbeatTimer = null;
    }
}

async function sendExplicitDenied(attendanceId) {
    try {
        const body = new URLSearchParams();
        body.set('attendance_id', String(attendanceId));
        body.set('denied', '1');
        body.set('explicit_denied', '1');

        const response = await fetch('../ajax/update_location.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'same-origin',
            body: body.toString(),
        });

        const data = await response.json();

        if (data.success) {
            updateGPSUI(
                'danger',
                'Location denied. Attendance marked <strong>absent</strong>. Penalty applied per event rules.'
            );
        }

        stopGPSMonitoring();
        locationFlowAttendanceId = null;
    } catch (err) {
        console.error('DENIED SEND ERROR:', err);
    }
}

function startGPSMonitoring(attendanceId) {
    handleLocationRequest(attendanceId);
}

function stopGPSMonitoring() {
    clearLocationWaitState();
    locationSubmitting = false;
    stopContinuousMonitoring();
    gpsActive = false;
    hideLocationModal();

    window.dispatchEvent(new CustomEvent('epassport:location-flow-ended'));
}

function showLogoutReminderModal(data) {
    const scanLabel = (data.scan_type || 'LOGOUT').replace(/_/g, ' ');
    const eventName = data.event_name || 'the event';
    let modal = document.getElementById('logoutReminderModal');

    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'logoutReminderModal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.innerHTML =
            '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content">' +
            '<div class="modal-header border-0 pb-0">' +
            '<h5 class="modal-title"><i class="bi bi-geo-alt-fill text-success me-2"></i>Logout recorded</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body pt-2" id="logoutReminderBody"></div>' +
            '<div class="modal-footer border-0 pt-0">' +
            '<button type="button" class="btn btn-success w-100" data-bs-dismiss="modal">Got it</button>' +
            '</div>' +
            '</div></div>';
        document.body.appendChild(modal);
    }

    const body = document.getElementById('logoutReminderBody');
    if (body) {
        body.innerHTML =
            '<p class="mb-2"><strong>Your ' + scanLabel + ' stamp was recorded.</strong></p>' +
            '<p class="mb-2">You may now <strong>turn off location services</strong> on your device. ' +
            'Live campus monitoring has ended for <strong>' + eventName + '</strong>.</p>' +
            '<p class="small text-muted mb-0">No further GPS updates are required until your next login stamp.</p>';
    }

    stopContinuousMonitoring();
    stopGPSMonitoring();
    gpsFilter.reset();

    if (typeof BackgroundMonitor !== 'undefined') {
        BackgroundMonitor.stop();
    }

    updateGPSUI(
        'success',
        '<strong>Logout recorded.</strong> You may turn off location services on your device.'
    );

    const instance = bootstrap.Modal.getOrCreateInstance(modal);
    instance.show();
}

async function handleLogoutReminder(data) {
    const attendanceId = parseInt(data.attendance_id, 10) || 0;

    if (!attendanceId) {
        return;
    }

    if (promptedLogoutId === attendanceId) {
        return;
    }

    promptedLogoutId = attendanceId;

    if (data.mark_realtime_event_id) {
        fetch('../ajax/ack_logout_reminder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: 'realtime_event_id=' + encodeURIComponent(String(data.mark_realtime_event_id)),
        }).catch(function () {});
    }

    showLogoutReminderModal(data);
}

function updateGPSUI(type, message) {
    const sessionStatus = document.getElementById('sessionStatus');

    if (!sessionStatus) {
        return;
    }

    const toneMap = {
        success: 'success',
        warning: 'warning',
        danger: 'danger',
        info: 'info',
        secondary: 'neutral',
    };
    const iconMap = {
        success: 'check-circle',
        warning: 'exclamation-triangle',
        danger: 'exclamation-octagon',
        info: 'info-circle',
        secondary: 'info-circle',
    };
    const tone = toneMap[type] || 'neutral';
    const icon = iconMap[type] || 'info-circle';

    sessionStatus.className = 'status-chip status-chip--' + tone;
    sessionStatus.textContent = '';
    const iconEl = document.createElement('i');
    iconEl.className = 'bi bi-' + icon;
    const spanEl = document.createElement('span');
    const tmp = document.createElement('div');
    tmp.innerHTML = message || '';
    spanEl.textContent = tmp.textContent || tmp.innerText || '';
    sessionStatus.appendChild(iconEl);
    sessionStatus.appendChild(spanEl);
}

function isContinuousMonitoringActive() {
    return monitoringAttendanceId !== null;
}

async function resumeMonitoringIfNeeded() {
    try {
        const response = await fetch('../ajax/get_monitoring_session.php', {
            credentials: 'same-origin',
        });
        const data = await response.json();

        if (!data.success || !data.monitoring || !data.attendance_id) {
            return;
        }

        if (monitoringAttendanceId === data.attendance_id) {
            activateBackgroundMonitor(data.attendance_id, data.event_name || '');
            return;
        }

        if (monitoringAttendanceId && monitoringAttendanceId !== data.attendance_id) {
            stopContinuousMonitoring();
        }

        startContinuousMonitoring(data.attendance_id, data.event_name || '');
        updateGPSUI(
            'success',
            'Live location tracking is active for <strong>' +
                (data.event_name || 'active event') +
                '</strong>. Keep GPS and internet ON — your position updates as you move.'
        );
    } catch (err) {
        console.warn('Could not resume monitoring:', err);
    }
}

window.epassportForceMonitoringPing = function () {
    if (monitoringAttendanceId) {
        captureMonitoringPositionOnce().then(function (result) {
            if (result.ok && result.position) {
                lastMonitoringSendAt = 0;
                sendMonitoringCoordinates(monitoringAttendanceId, result.position);
            }
        });
    }
};

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', function () {
        resumeMonitoringIfNeeded();
    });
}
