<?php
require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/maps_helper.php';
require_once 'layout.php';

$preselectEventId = (int) ($_GET['event_id'] ?? 0);

if ($preselectEventId > 0 && empty($_GET['standalone'])) {
    header("Location: event_setup.php?event_id={$preselectEventId}&step=2");
    exit;
}

$db = new Database();
$conn = $db->connect();

$stmt = $conn->prepare("
    SELECT event_id, event_name, geofence_latitude, geofence_longitude, geofence_radius, is_active
    FROM events
    ORDER BY created_at DESC
");
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeEvent = getActiveEvent($conn);
$defaultEventId = $preselectEventId;

if ($defaultEventId <= 0 && $activeEvent) {
    $defaultEventId = (int) $activeEvent['event_id'];
}

$campus = campusMapConfig();
$mapOptions = googleMapsDefaultOptions($campus);

renderAdminHeader('Live Geofence Map', 'geofence_map');
?>

<section class="admin-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 mb-0">Geofence Management — <?= htmlspecialchars($campus['name']) ?> (<?= htmlspecialchars($campus['postal_code']) ?>)</h2>
        <a href="live_tracking.php" class="btn btn-outline-success btn-sm">Go to Live Student Tracking</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label">Select Event</label>
            <select id="eventSelect" class="form-select">
                <option value="">-- Select Event --</option>
                <?php foreach ($events as $event): ?>
                    <option
                        value="<?= (int)$event['event_id'] ?>"
                        data-lat="<?= htmlspecialchars((string)$event['geofence_latitude']) ?>"
                        data-lng="<?= htmlspecialchars((string)$event['geofence_longitude']) ?>"
                        data-radius="<?= htmlspecialchars((string)$event['geofence_radius']) ?>"
                        data-active="<?= (int) $event['is_active'] === 1 ? '1' : '0' ?>"
                        <?= $defaultEventId === (int) $event['event_id'] ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($event['event_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Radius (meters)</label>
            <input type="number" id="radiusInput" class="form-control" value="<?= (int) $campus['default_radius'] ?>" min="1">
        </div>

        <div class="col-md-3 d-flex align-items-end">
            <button type="button" class="btn btn-primary w-100" id="saveGeofenceBtn" onclick="saveGeofence()">
                Save Geofence
            </button>
        </div>
    </div>

    <div id="result" class="alert alert-info py-2">
        <?php if ($activeEvent && eventHasGeofence($activeEvent)): ?>
            Active event geofence loaded. Pan and zoom freely — map starts at the saved boundary.
        <?php else: ?>
            Map opens on USTP Panaon and Punta Meriam. Pan and zoom freely — click the map to set a geofence center.
        <?php endif; ?>
    </div>

    <div id="geofenceMap" class="admin-map" aria-label="Geofence map"></div>
</section>

<style>
    #geofenceMap.admin-map {
        height: 520px;
        width: 100%;
        min-height: 420px;
        border-radius: 10px;
        border: 1px solid #d7def2;
        position: relative;
        z-index: 1;
    }
</style>

<?= googleMapsHelperScript() ?>

<script>
const campusMap = <?= json_encode($campus, JSON_UNESCAPED_UNICODE) ?>;
const mapDefaults = <?= json_encode($mapOptions, JSON_UNESCAPED_UNICODE) ?>;

let map;
let marker = null;
let circle = null;
let selectedLat = null;
let selectedLng = null;

const PANAON_CENTER = { lat: campusMap.latitude, lng: campusMap.longitude };

function clearMapObjects() {
    EpassportMaps.removeMapOverlay(marker);
    EpassportMaps.removeMapOverlay(circle);
    marker = null;
    circle = null;
}

function getCurrentRadius() {
    return parseInt(document.getElementById('radiusInput').value, 10) || campusMap.default_radius || 200;
}

function drawGeofenceOverlay(center, radius, isPreview = false) {
    clearMapObjects();

    const label = isPreview ? 'C' : 'G';
    const title = isPreview ? 'Default campus center' : 'Geofence center';
    const latLng = EpassportMaps.normalizeLatLng(center);

    marker = EpassportMaps.createGeofenceMarker(map, latLng, label, title);

    circle = new google.maps.Circle({
        map: map,
        center: latLng,
        radius: radius,
        fillColor: '#1f2f88',
        fillOpacity: isPreview ? 0.08 : 0.18,
        strokeColor: '#1f2f88',
        strokeWeight: 2,
        strokeOpacity: isPreview ? 0.5 : 0.9,
        clickable: false
    });
}

function showCampusPreview() {
    const radius = getCurrentRadius();
    drawGeofenceOverlay(PANAON_CENTER, radius, true);
    EpassportMaps.focusOpeningArea(map, campusMap);
}

function bootGeofenceMap() {
    const mapEl = document.getElementById('geofenceMap');
    if (!mapEl) {
        console.error('Geofence map container #geofenceMap was not found.');
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

    map = new google.maps.Map(mapEl, EpassportMaps.createOptions(mapDefaults));

    google.maps.event.addListenerOnce(map, 'idle', function () {
        google.maps.event.trigger(map, 'resize');
        if (selectedLat === null || selectedLng === null) {
            EpassportMaps.focusOpeningArea(map, campusMap);
        }
    });

    map.addListener('click', function (e) {
        const selectedOption = document.getElementById('eventSelect').selectedOptions[0];
        if (selectedOption && selectedOption.dataset.active === '1') {
            return;
        }
        selectedLat = e.latLng.lat();
        selectedLng = e.latLng.lng();
        const radius = getCurrentRadius();
        drawGeofenceOverlay(e.latLng, radius, false);
        EpassportMaps.fitToGeofence(map, selectedLat, selectedLng, radius);
    });

    document.getElementById('eventSelect').addEventListener('change', loadSelectedEventGeofence);
    document.getElementById('radiusInput').addEventListener('change', function () {
        if (selectedLat !== null && selectedLng !== null) {
            const radius = getCurrentRadius();
            drawGeofenceOverlay({ lat: selectedLat, lng: selectedLng }, radius, false);
            EpassportMaps.fitToGeofence(map, selectedLat, selectedLng, radius);
            return;
        }
        showCampusPreview();
    });

    const select = document.getElementById('eventSelect');
    if (select.value) {
        loadSelectedEventGeofence();
    } else {
        showCampusPreview();
    }
}

function saveGeofence() {
    const eventId = document.getElementById('eventSelect').value;
    const radius = document.getElementById('radiusInput').value;
    const selectedOption = document.getElementById('eventSelect').selectedOptions[0];

    if (selectedOption && selectedOption.dataset.active === '1') {
        epassportAlert('This event is active. Deactivate it first to change the geofence.', 'Geofence locked');
        return;
    }

    if (!eventId || selectedLat === null || selectedLng === null) {
        epassportAlert('Select an event and click the map to set the geofence center.', 'Geofence');
        return;
    }

    fetch('../ajax/save_geofence.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `event_id=${eventId}&lat=${selectedLat}&lng=${selectedLng}&radius=${radius}`
    })
    .then(res => res.json())
    .then(data => {
        const result = document.getElementById('result');
        result.className = `alert py-2 ${data.success ? 'alert-success' : 'alert-danger'}`;
        result.textContent = data.message;
    });
}

function setGeofenceFormLocked(locked) {
    const radiusInput = document.getElementById('radiusInput');
    const saveBtn = document.getElementById('saveGeofenceBtn');
    if (radiusInput) radiusInput.disabled = locked;
    if (saveBtn) saveBtn.disabled = locked;
}

function loadSelectedEventGeofence() {
    const selectedOption = document.getElementById('eventSelect').selectedOptions[0];
    if (!selectedOption || !selectedOption.value) {
        selectedLat = null;
        selectedLng = null;
        setGeofenceFormLocked(false);
        showCampusPreview();
        return;
    }

    const isActive = selectedOption.dataset.active === '1';
    setGeofenceFormLocked(isActive);

    const lat = parseFloat(selectedOption.dataset.lat);
    const lng = parseFloat(selectedOption.dataset.lng);
    const radius = parseInt(selectedOption.dataset.radius, 10);

    if (!isNaN(radius) && radius > 0) {
        document.getElementById('radiusInput').value = radius;
    }

    if (isNaN(lat) || isNaN(lng)) {
        selectedLat = null;
        selectedLng = null;
        showCampusPreview();
        return;
    }

    selectedLat = lat;
    selectedLng = lng;
    const fenceRadius = getCurrentRadius();
    drawGeofenceOverlay({ lat, lng }, fenceRadius, false);
    EpassportMaps.fitToGeofence(map, lat, lng, fenceRadius);
}

// Google Maps API calls this global when the script finishes loading.
window.initGeofenceMap = function () {
    if (window.__geofenceMapBooted) {
        return;
    }

    window.__geofenceMapBooted = true;
    bootGeofenceMap();
};

// Fallback if the API callback is blocked or delayed.
window.addEventListener('load', function () {
    window.setTimeout(function () {
        if (window.__geofenceMapBooted) {
            return;
        }
        if (typeof google !== 'undefined' && google.maps && typeof EpassportMaps !== 'undefined') {
            console.warn('Geofence map: invoking initGeofenceMap fallback.');
            window.initGeofenceMap();
        }
    }, 2500);
});
</script>

<?= googleMapsHelperScript() ?>
<?= googleMapsApiScript('initGeofenceMap') ?>

<?php renderAdminFooter(); ?>
