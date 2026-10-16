<?php
require_once '../includes/session.php';
requireRole(3);
require_once '../config/database.php';
require_once '../includes/event_helpers.php';

$db = new Database();
$conn = $db->connect();

$officerEventId = isset($_SESSION['officer_selected_event_id'])
    ? (int) $_SESSION['officer_selected_event_id']
    : 0;

$resolved = resolveOfficerScanEvent($conn, $officerEventId);
$scanAllowed = $resolved['event'] && empty($resolved['error']);
$scanBlockReason = $resolved['error'] ?? 'Scanning is not available.';
$activeScanType = $scanAllowed
    ? ($resolved['availability']['scan_rule']['scan_type'] ?? '')
    : '';
$activeEventName = $scanAllowed
    ? ($resolved['event']['event_name'] ?? '')
    : (($resolved['event']['event_name'] ?? '') ?: '');

$lateEvent = $resolved['event'] ?? getActiveEvent($conn);
$lateAvailability = $lateEvent ? getLateScanAvailability($conn, $lateEvent) : ['allowed' => false, 'reason' => '', 'scan_rule' => null];
$lateAllowed = !empty($lateAvailability['allowed']);
$lateScanType = $lateAllowed ? ($lateAvailability['scan_rule']['scan_type'] ?? '') : '';
$cameraAllowed = $scanAllowed || $lateAllowed;
$startInLateMode = $lateAllowed && (!$scanAllowed || isset($_GET['late']));
$cameraNeedsHttps = !epassport_is_https_request() && !epassport_is_localhost_request();
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Officer QR Scanner</title>

    <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap-icons/bootstrap-icons.css')) ?>">
    <link rel="stylesheet" href="../assets/css/officer.css">

    <style>
        body {
            background: #f5f7fb;
        }

        .scanner-card {
            max-width: 1000px;
            margin: auto;
        }

        .camera-stage {
            position: relative;
            width: 100%;
            aspect-ratio: 4 / 3;
            max-height: 420px;
            border-radius: 10px;
            border: 2px solid #ddd;
            background: #000;
            overflow: hidden;
        }

        .camera-stage video,
        .camera-stage img,
        .camera-stage canvas {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            position: relative;
            z-index: 1;
            background: #000;
            transform: translateZ(0);
        }

        .camera-stage .capture-preview {
            display: none;
        }

        .camera-stage.is-frozen video {
            display: none;
        }

        .camera-stage.is-frozen .capture-preview {
            display: block;
        }

        .viewfinder {
            position: absolute;
            inset: 12%;
            border: 2px dashed rgba(255, 255, 255, 0.85);
            border-radius: 12px;
            pointer-events: none;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.25);
        }

        .viewfinder::after {
            content: 'Align QR inside frame';
            position: absolute;
            left: 50%;
            bottom: -2rem;
            transform: translateX(-50%);
            color: #fff;
            font-size: 0.8rem;
            white-space: nowrap;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8);
        }

        .camera-stage.is-frozen .viewfinder::after {
            content: 'Captured — scanning…';
        }

        .camera-stage.is-scanning .viewfinder::after {
            content: 'Scanning — hold QR in frame';
        }

        .camera-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            font-size: 0.95rem;
            text-align: center;
            padding: 1rem;
        }

        .camera-stage.has-feed .camera-placeholder {
            display: none;
        }

        #captureCanvas {
            display: none;
        }
    </style>

</head>

<body>

<div class="container py-4">

    <div class="card shadow border-0 scanner-card">

        <div class="card-body">

            <div class="d-flex justify-content-between align-items-center mb-4">

                <h2 class="mb-0">Officer QR Scanner</h2>

                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                    Back
                </a>

            </div>

            <?php if ($cameraNeedsHttps): ?>
                <div class="alert alert-danger py-2">
                    <strong>Camera blocked on HTTP.</strong>
                    Phones and many browsers only allow the camera on HTTPS (or <code>localhost</code>).
                    Open this scanner as
                    <code>https://<?= htmlspecialchars((string) ($_SERVER['HTTP_HOST'] ?? '')) ?>/E-PASSPORT/officer/scanner.php</code>
                    or use <code>http://localhost/E-PASSPORT/officer/scanner.php</code> on this PC.
                </div>
            <?php endif; ?>

            <?php if ($scanAllowed): ?>
                <div class="alert alert-success py-2" id="scanModeBanner">
                    <strong><?= htmlspecialchars($activeEventName) ?></strong>
                    — accepting <strong><?= htmlspecialchars(formatScanTypeLabel($activeScanType)) ?></strong> stamps only (<?= htmlspecialchars(appTimezoneLabel()) ?> <?= appNow('g:i A', $conn) ?>).
                </div>
            <?php elseif ($lateAllowed): ?>
                <div class="alert alert-warning py-2" id="scanModeBanner">
                    Regular window is closed.
                    <strong>Scan Late Students</strong> is available for
                    <strong><?= htmlspecialchars(formatScanTypeLabel($lateScanType)) ?></strong>
                    (<?= htmlspecialchars($lateEvent['event_name'] ?? '') ?>).
                    Attendance will be marked <strong>Late</strong>.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <strong>Scanner unavailable.</strong>
                    <?= htmlspecialchars($scanBlockReason) ?>
                    <?php if (!empty($lateAvailability['reason'])): ?>
                        <br><?= htmlspecialchars($lateAvailability['reason']) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="row g-4">

                <div class="col-lg-7">

                    <div id="cameraStage" class="camera-stage">
                        <div class="camera-placeholder">
                            Tap <strong>Start Camera</strong> to open the scanner
                        </div>
                        <video id="video" autoplay muted playsinline webkit-playsinline></video>
                        <img id="capturePreview" class="capture-preview" alt="Captured frame">
                        <div class="viewfinder" id="viewfinder"></div>
                    </div>

                    <canvas id="captureCanvas"></canvas>
                    <input type="file" id="mobileCameraInput" accept="image/*" capture="environment" hidden>

                    <div class="mt-3 d-grid gap-2">

                        <button id="startCameraBtn" class="btn btn-primary btn-lg">
                            Start Camera
                        </button>

                        <?php if ($lateAllowed): ?>
                        <button id="lateScanBtn" type="button" class="btn btn-warning btn-lg <?= $startInLateMode ? 'active' : '' ?>">
                            Scan Late Students
                        </button>
                        <?php endif; ?>

                        <button id="captureBtn" class="btn btn-success btn-lg" disabled>
                            Capture &amp; Scan
                        </button>

                        <button id="retakeBtn" class="btn btn-outline-secondary" disabled hidden>
                            Retake Photo
                        </button>

                    </div>

                </div>

                <div class="col-lg-5">

                    <div id="result" class="alert alert-info">
                        Tap <strong>Start Camera</strong>, point at the student QR inside the frame — it scans automatically.
                    </div>

                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <h5>How to scan</h5>
                            <ol class="mb-0 ps-3">
                                <li>Student refreshes their dashboard (new QR)</li>
                                <li>Maximize brightness; QR fills the dashed frame</li>
                                <li>Hold steady — the scanner reads the QR automatically</li>
                            </ol>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<script src="<?= htmlspecialchars(epassport_vendor_asset('jsqr/jsQR.min.js')) ?>"></script>
<script src="<?= htmlspecialchars(epassport_vendor_asset('zxing/index.min.js')) ?>"></script>

<script>

const SCAN_ALLOWED = <?= $cameraAllowed ? 'true' : 'false' ?>;
const LATE_ALLOWED = <?= $lateAllowed ? 'true' : 'false' ?>;
const LATE_SCAN_LABEL = <?= json_encode(formatScanTypeLabel($lateScanType)) ?>;
let lateScanMode = <?= $startInLateMode ? 'true' : 'false' ?>;

const isMobileDevice = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
const SCAN_INTERVAL_MS = isMobileDevice ? 120 : 80;

let mediaStream = null;
let codeReader = null;
let cameraActive = false;
let processingScan = false;
let lastFailedQr = '';
let lastFailedAt = 0;
let scanLoopId = null;
let lastScanMs = 0;
let scanFrameCount = 0;
const FAIL_RETRY_MS = 4000;

const video = document.getElementById('video');
const captureCanvas = document.getElementById('captureCanvas');
const capturePreview = document.getElementById('capturePreview');
const cameraStage = document.getElementById('cameraStage');
const resultContainer = document.getElementById('result');
const startCameraBtn = document.getElementById('startCameraBtn');
const captureBtn = document.getElementById('captureBtn');
const retakeBtn = document.getElementById('retakeBtn');
const mobileCameraInput = document.getElementById('mobileCameraInput');

if (isMobileDevice) {
    captureBtn.hidden = true;
}

function showResult(type, message) {
    resultContainer.className = `alert alert-${type}`;
    resultContainer.innerHTML = message;
}

function setUiCameraLive() {
    cameraStage.classList.add('has-feed');
    cameraStage.classList.remove('is-frozen');
    capturePreview.removeAttribute('src');
    captureBtn.disabled = !cameraActive || processingScan;
    retakeBtn.disabled = true;
    retakeBtn.hidden = true;
    startCameraBtn.textContent = cameraActive ? 'Restart Camera' : 'Start Camera';
}

function setUiFrozen() {
    cameraStage.classList.add('is-frozen');
    captureBtn.disabled = true;
    retakeBtn.disabled = processingScan;
    retakeBtn.hidden = false;
}

function tryTextFromRawBytes(result) {
    try {
        const bytes =
            typeof result.getRawBytes === 'function'
                ? result.getRawBytes()
                : result.rawBytes;

        if (!bytes || !bytes.length) {
            return '';
        }

        const u8 = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);

        if (typeof TextDecoder !== 'undefined') {
            const decoded = new TextDecoder('utf-8', { fatal: false }).decode(u8);
            const t = String(decoded).trim();
            if (t !== '') {
                return t;
            }
        }
    } catch (e) {
        console.warn('rawBytes decode failed', e);
    }

    return '';
}

function extractQrText(result) {
    if (!result) {
        return '';
    }

    let r = result;

    if (
        r &&
        typeof r === 'object' &&
        r.result &&
        (typeof r.result.getText === 'function' || r.result.text != null)
    ) {
        r = r.result;
    }

    try {
        if (typeof r.getText === 'function') {
            const t = r.getText();
            if (t != null && String(t).trim() !== '') {
                return String(t);
            }
        }
    } catch (e) {
        console.warn('getText failed', e);
    }

    if (r.text != null && String(r.text).trim() !== '') {
        return String(r.text);
    }

    return tryTextFromRawBytes(r);
}

function getCodeReader() {
    if (typeof ZXing === 'undefined') {
        throw new Error('QR reader is not available.');
    }

    if (!codeReader) {
        const ReaderCtor =
            ZXing.BrowserQRCodeReader ||
            ZXing.BrowserMultiFormatReader;

        if (typeof ReaderCtor !== 'function') {
            throw new Error('QR reader is not available.');
        }

        codeReader = new ReaderCtor();
    }
    return codeReader;
}

/**
 * ZXing decodeFromImageElement expects HTMLImageElement (uses .complete).
 * Canvas elements do not have .complete — convert via data URL first.
 */
function loadImageFromDataUrl(dataUrl) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () =>
            reject(new Error('Failed to load captured image for scanning.'));
        img.src = dataUrl;
    });
}

function waitForImageElement(img) {
    if (img.complete && img.naturalWidth > 0) {
        return Promise.resolve(img);
    }

    return new Promise((resolve, reject) => {
        img.onload = () => resolve(img);
        img.onerror = () =>
            reject(new Error('Captured preview failed to load.'));
    });
}

async function decodeQrFromCanvas(canvas) {
    const reader = getCodeReader();
    const dataUrl = canvas.toDataURL('image/png');

    if (typeof reader.decodeFromImageUrl === 'function') {
        const result = await reader.decodeFromImageUrl(dataUrl);
        return extractQrText(result).trim();
    }

    const img = await loadImageFromDataUrl(dataUrl);

    if (!img.complete || img.naturalWidth === 0) {
        await waitForImageElement(img);
    }

    const result = await reader.decodeFromImageElement(img);
    return extractQrText(result).trim();
}

function stopCamera() {
    stopLiveScanLoop();

    if (mediaStream) {
        mediaStream.getTracks().forEach((track) => track.stop());
        mediaStream = null;
    }
    video.srcObject = null;
    cameraActive = false;
    cameraStage.classList.remove('has-feed', 'is-frozen', 'is-scanning');
    captureBtn.disabled = true;
}

function decodeJsQrFromCanvas(canvas, tryInversion = false) {
    if (typeof jsQR !== 'function') {
        return '';
    }

    const w = canvas.width;
    const h = canvas.height;

    if (!w || !h) {
        return '';
    }

    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const imageData = ctx.getImageData(0, 0, w, h);
    const code = jsQR(imageData.data, w, h, {
        inversionAttempts: tryInversion ? 'attemptBoth' : 'dontInvert'
    });

    return code && code.data ? String(code.data).trim() : '';
}

function decodeQrFromVideoFrame() {
    const w = video.videoWidth;
    const h = video.videoHeight;

    if (!w || !h) {
        return '';
    }

    const inset = 0.12;
    const sx = Math.round(w * inset);
    const sy = Math.round(h * inset);
    const sw = Math.round(w * (1 - inset * 2));
    const sh = Math.round(h * (1 - inset * 2));

    captureCanvas.width = sw;
    captureCanvas.height = sh;
    captureCanvas.getContext('2d', { willReadFrequently: true })
        .drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);

    scanFrameCount += 1;

    return decodeJsQrFromCanvas(captureCanvas, scanFrameCount % 5 === 0);
}

function startLiveScanLoop() {
    stopLiveScanLoop();
    cameraStage.classList.add('is-scanning');

    const tick = (now) => {
        if (!cameraActive) {
            return;
        }

        if (!processingScan && now - lastScanMs >= SCAN_INTERVAL_MS) {
            lastScanMs = now;

            try {
                const qrText = decodeQrFromVideoFrame();

                if (qrText) {
                    handleAutoDetectedQr(qrText);
                    return;
                }
            } catch (error) {
                console.warn('Live scan frame error:', error);
            }
        }

        scanLoopId = requestAnimationFrame(tick);
    };

    scanLoopId = requestAnimationFrame(tick);
}

function stopLiveScanLoop() {
    if (scanLoopId) {
        cancelAnimationFrame(scanLoopId);
        scanLoopId = null;
    }

    cameraStage.classList.remove('is-scanning');
}

function resumeLiveScanning() {
    if (cameraActive && !processingScan) {
        startLiveScanLoop();
        showResult(
            'info',
            'Point the camera at the QR code — it will scan automatically.'
        );
    }
}

async function handleAutoDetectedQr(qrText) {
    const clean = String(qrText ?? '').trim();

    if (processingScan || !SCAN_ALLOWED || clean === '') {
        return;
    }

    if (clean === lastFailedQr && Date.now() - lastFailedAt < FAIL_RETRY_MS) {
        return;
    }

    processingScan = true;
    stopLiveScanLoop();

    showResult('info', 'QR detected. Confirming student identity…');

    await processAttendance(clean, { resumeOnFailure: true });
}

async function requestCameraStream() {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        throw new Error('Camera is not supported in this browser. Use Chrome or Edge.');
    }

    const attempts = isMobileDevice
        ? [
            { video: { facingMode: { ideal: 'environment' } }, audio: false },
            { video: true, audio: false },
        ]
        : [
            { video: true, audio: false },
            { video: { facingMode: 'user' }, audio: false },
        ];

    let lastError = null;

    for (let i = 0; i < attempts.length; i++) {
        try {
            return await navigator.mediaDevices.getUserMedia(attempts[i]);
        } catch (error) {
            lastError = error;
        }
    }

    throw lastError || new Error('Could not access the camera.');
}

function waitForVideoReady(el, timeoutMs) {
    if (el.readyState >= 2 && el.videoWidth > 0) {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const timer = window.setTimeout(() => {
            reject(new Error('Camera opened but no picture appeared. Close other apps using the camera, then try again.'));
        }, timeoutMs);

        const done = () => {
            window.clearTimeout(timer);
            el.removeEventListener('loadeddata', done);
            resolve();
        };

        el.addEventListener('loadeddata', done);
    });
}

function cameraErrorMessage(error) {
    const name = error && error.name ? String(error.name) : '';
    const raw = error && error.message ? String(error.message) : '';

    if (!window.isSecureContext) {
        return 'This browser blocks the camera on unsecured HTTP. Open the scanner with HTTPS, or with http://localhost/… on this computer.';
    }

    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
        return 'Camera permission was blocked. Click the camera/lock icon in the address bar, allow Camera, then tap Start Camera again.';
    }

    if (name === 'NotFoundError' || name === 'OverconstrainedError' || name === 'DevicesNotFoundError') {
        return 'No usable camera was found. Close other apps using the camera (Zoom, Teams, another Chrome tab) and try again.';
    }

    if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
        return 'The camera is busy or already in use. Close other tabs or apps using it, then tap Start Camera again.';
    }

    return raw || 'Could not access the camera. Allow camera permission and try again.';
}

async function handleNativeCameraPhoto(file) {
    if (!file || processingScan) {
        return;
    }

    if (!SCAN_ALLOWED) {
        showResult('warning', 'Scanning is not open. Check the officer dashboard for the current scan window.');
        return;
    }

    processingScan = true;
    retakeBtn.disabled = true;

    const previewUrl = URL.createObjectURL(file);

    try {
        showResult('info', 'Reading QR code…');

        const img = await loadImageFromDataUrl(previewUrl);
        captureCanvas.width = img.naturalWidth;
        captureCanvas.height = img.naturalHeight;
        captureCanvas.getContext('2d', { willReadFrequently: true }).drawImage(img, 0, 0);

        capturePreview.src = previewUrl;
        cameraStage.classList.add('has-feed', 'is-frozen');
        retakeBtn.hidden = false;
        retakeBtn.disabled = false;

        let qrText = decodeJsQrFromCanvas(captureCanvas, false);

        if (!qrText) {
            qrText = decodeJsQrFromCanvas(captureCanvas, true);
        }

        if (!qrText) {
            throw new Error('QR code not found in the photo.');
        }

        await processAttendance(qrText);
    } catch (error) {
        console.error(error);

        const msg =
            error.message === 'QR code not found in the photo.'
                ? 'No QR code detected. Ask the student to brighten the screen, fill the frame, then tap <strong>Retake Photo</strong>.'
                : (error.message || 'Could not read the QR from this photo.');

        showResult('danger', msg + '<br><br>Tap <strong>Retake Photo</strong> and try again.');
        retakeBtn.disabled = false;
    } finally {
        processingScan = false;
        URL.revokeObjectURL(previewUrl);
    }
}

async function startCamera() {
    if (processingScan) {
        return;
    }

    if (!window.isSecureContext) {
        showResult(
            'danger',
            cameraErrorMessage({ name: 'SecurityError' })
        );
        return;
    }

    if (typeof jsQR === 'undefined' && typeof ZXing === 'undefined') {
        showResult(
            'danger',
            'Scanner library failed to load. Refresh the page (Ctrl+F5) and try again.'
        );
        return;
    }

    try {
        stopCamera();
        setUiCameraLive();

        showResult('info', 'Opening camera… Allow permission if the browser asks.');

        mediaStream = await requestCameraStream();
        video.srcObject = mediaStream;
        video.muted = true;
        video.setAttribute('playsinline', 'true');
        video.setAttribute('webkit-playsinline', 'true');

        try {
            await video.play();
        } catch (playError) {
            console.warn('video.play() deferred until frames load', playError);
        }

        await waitForVideoReady(video, 8000);

        if (video.paused) {
            await video.play();
        }

        cameraActive = true;
        setUiCameraLive();
        startLiveScanLoop();

        if (!SCAN_ALLOWED) {
            showResult(
                'warning',
                'Camera is on, but scanning is not open yet. Check the officer dashboard for the current window. You can still test the camera.'
            );
            return;
        }

        showResult(
            'info',
            'Camera ready. Point the student QR inside the frame — scanning is automatic.'
        );

    } catch (error) {
        console.error(error);
        stopCamera();

        if (isMobileDevice && !window.isSecureContext) {
            showResult(
                'warning',
                'Live camera needs HTTPS on this phone. Opening the phone camera to take a photo instead…'
            );
            mobileCameraInput.click();
            return;
        }

        showResult('danger', cameraErrorMessage(error));
    }
}

function buildCaptureCanvases() {
    const w = video.videoWidth;
    const h = video.videoHeight;

    if (!w || !h) {
        throw new Error('Camera is not ready yet. Wait a moment and try again.');
    }

    captureCanvas.width = w;
    captureCanvas.height = h;

    const ctx = captureCanvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, 0, 0, w, h);

    const variants = [captureCanvas];

    const inset = 0.12;
    const cropW = Math.round(w * (1 - inset * 2));
    const cropH = Math.round(h * (1 - inset * 2));

    if (cropW > 80 && cropH > 80) {
        const cropped = document.createElement('canvas');
        cropped.width = cropW;
        cropped.height = cropH;
        const cctx = cropped.getContext('2d', { willReadFrequently: true });
        cctx.drawImage(
            captureCanvas,
            Math.round(w * inset),
            Math.round(h * inset),
            cropW,
            cropH,
            0,
            0,
            cropW,
            cropH
        );
        variants.push(cropped);
    }

    const scales = [1.5, 2, 2.5];

    for (const source of [...variants]) {
        for (const scale of scales) {
            const scaled = document.createElement('canvas');
            scaled.width = Math.round(source.width * scale);
            scaled.height = Math.round(source.height * scale);
            const sctx = scaled.getContext('2d', { willReadFrequently: true });
            sctx.imageSmoothingEnabled = false;
            sctx.drawImage(source, 0, 0, scaled.width, scaled.height);
            variants.push(scaled);
        }
    }

    return variants;
}

async function decodeCapturedFrame() {
    const sources = buildCaptureCanvases();
    let lastError = null;

    for (const canvas of sources) {
        try {
            const text = await decodeQrFromCanvas(canvas);
            if (text !== '') {
                return text;
            }
        } catch (err) {
            if (
                err &&
                String(err.message || '').includes('complete')
            ) {
                err = new Error(
                    'Scanner could not process this capture format.'
                );
            }
            lastError = err;
        }
    }

    if (lastError && lastError.name !== 'NotFoundException') {
        const notFound =
            typeof ZXing !== 'undefined' &&
            ZXing.NotFoundException &&
            lastError instanceof ZXing.NotFoundException;

        if (notFound) {
            lastError.name = 'NotFoundException';
        }
    }

    throw lastError || new Error('QR code not found in the captured image.');
}

async function captureAndScan() {
    if (!SCAN_ALLOWED) {
        showResult('warning', 'Scanning is not open. Check the officer dashboard for the current scan window.');
        return;
    }

    if (!cameraActive || processingScan) {
        return;
    }

    processingScan = true;
    captureBtn.disabled = true;

    try {
        const ctx = captureCanvas.getContext('2d', { willReadFrequently: true });
        captureCanvas.width = video.videoWidth;
        captureCanvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0, captureCanvas.width, captureCanvas.height);

        const previewUrl = captureCanvas.toDataURL('image/jpeg', 0.95);
        capturePreview.src = previewUrl;
        setUiFrozen();

        showResult('info', 'Image captured. Reading QR code…');

        await waitForImageElement(capturePreview);

        let qrText = decodeJsQrFromCanvas(captureCanvas, true);

        if (!qrText) {
            try {
                const reader = getCodeReader();
                if (typeof reader.decodeFromImageUrl === 'function') {
                    const result = await reader.decodeFromImageUrl(previewUrl);
                    qrText = extractQrText(result).trim();
                } else {
                    const result = await reader.decodeFromImageElement(capturePreview);
                    qrText = extractQrText(result).trim();
                }
            } catch (previewErr) {
                console.warn('Preview decode failed, trying variants:', previewErr);
            }
        }

        if (!qrText) {
            qrText = await decodeCapturedFrame();
        }
        console.log('QR from capture:', qrText);

        await processAttendance(qrText);
        processingScan = false;

    } catch (error) {
        console.error(error);

        const msg =
            error && error.name === 'NotFoundException'
                ? 'No QR code detected. Ask the student to brighten the screen, fill the frame, then tap <strong>Retake Photo</strong>.'
                : (error.message || 'Could not read the QR from this capture.');

        showResult('danger', msg + '<br><br>Tap <strong>Retake Photo</strong> and try again.');

        processingScan = false;
        retakeBtn.disabled = false;
    }
}

function retakePhoto() {
    if (processingScan) {
        return;
    }

    if (!cameraActive) {
        capturePreview.removeAttribute('src');
        cameraStage.classList.remove('has-feed', 'is-frozen');
        retakeBtn.hidden = true;
        mobileCameraInput.value = '';
        mobileCameraInput.click();
        return;
    }

    setUiCameraLive();
    resumeLiveScanning();
}

async function processAttendance(qrText, options = {}) {
    const resumeOnFailure = options.resumeOnFailure === true;

    try {
        const clean = String(qrText ?? '').trim();

        if (clean === '') {
            showResult(
                'danger',
                'QR data was empty. Point the camera at a valid student QR and try again.'
            );
            processingScan = false;
            retakeBtn.disabled = false;
            if (resumeOnFailure) {
                resumeLiveScanning();
            }
            return;
        }

        showResult('info', 'QR read successfully. Confirming student identity…');

        const payload = new URLSearchParams();
        payload.set('qr_data', clean);
        payload.set('late_scan', lateScanMode ? '1' : '0');

        const response = await fetch('process_scan.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            credentials: 'same-origin',
            body: payload.toString()
        });

        const text = await response.text();
        let data;

        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            console.error('Invalid JSON from server:', text);
            throw new Error('Server returned an invalid response.');
        }

        if (!data.success) {
            lastFailedQr = clean;
            lastFailedAt = Date.now();
            showResult('danger', data.message || 'Scan was not accepted.');
            processingScan = false;
            retakeBtn.disabled = false;
            if (resumeOnFailure) {
                setTimeout(function () {
                    if (!processingScan) {
                        resumeLiveScanning();
                    }
                }, 2500);
            }
            return;
        }

        lastFailedQr = '';
        lastFailedAt = 0;

        const alertType = data.already_present ? 'warning' : 'success';
        showResult(
            alertType,
            `
            <strong>${data.message}</strong>
            <hr>
            Student No: <strong>${data.student_no ?? '—'}</strong><br>
            Name: ${data.student_name ?? '—'}<br>
            Event: ${data.event_name ?? '—'}<br>
            Stamp: ${data.scan_type ?? '—'}
            ${data.scan_window ? `<br>Window: ${data.scan_window}` : ''}
            ${data.gps_note ? `<hr><small>${data.gps_note}</small>` : ''}
            <hr>
            <button type="button" class="btn btn-primary btn-sm" id="scanNextBtn">Scan Next Student</button>
            `
        );

        document.getElementById('scanNextBtn').addEventListener('click', resetForNextStudent);

    } catch (error) {
        console.error(error);
        lastFailedQr = String(qrText ?? '').trim();
        lastFailedAt = Date.now();
        showResult('danger', 'Attendance processing failed. Retake or try again.');
        processingScan = false;
        retakeBtn.disabled = false;
        if (options.resumeOnFailure) {
            setTimeout(function () {
                if (!processingScan) {
                    resumeLiveScanning();
                }
            }, 2500);
        }
    }
}

function resetForNextStudent() {
    processingScan = false;
    setUiCameraLive();

    if (cameraActive) {
        resumeLiveScanning();
    } else {
        startCamera();
    }
}

startCameraBtn.addEventListener('click', startCamera);
captureBtn.addEventListener('click', captureAndScan);
retakeBtn.addEventListener('click', retakePhoto);

const lateScanBtn = document.getElementById('lateScanBtn');
if (lateScanBtn) {
    function syncLateButton() {
        lateScanBtn.classList.toggle('active', lateScanMode);
        lateScanBtn.textContent = lateScanMode
            ? ('Late scan ON — ' + (LATE_SCAN_LABEL || 'closed window'))
            : 'Scan Late Students';
    }
    syncLateButton();
    lateScanBtn.addEventListener('click', function () {
        if (!LATE_ALLOWED) {
            return;
        }
        lateScanMode = !lateScanMode;
        if (!<?= $scanAllowed ? 'true' : 'false' ?>) {
            lateScanMode = true;
        }
        syncLateButton();
        showResult(
            lateScanMode ? 'warning' : 'info',
            lateScanMode
                ? 'Late scan mode is on. Students will be marked Late and the late penalty will apply after a successful login check.'
                : 'Regular scan mode.'
        );
    });
}
mobileCameraInput.addEventListener('change', () => {
    const file = mobileCameraInput.files && mobileCameraInput.files[0];
    if (file) {
        handleNativeCameraPhoto(file);
    }
});

if (isMobileDevice && SCAN_ALLOWED && window.isSecureContext) {
    window.addEventListener('load', () => startCamera());
}

window.addEventListener('beforeunload', stopCamera);

</script>
<?php
require_once __DIR__ . '/../includes/background_jobs.php';
epassport_render_background_jobs_script();
?>
</body>
</html>
