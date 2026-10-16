<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../includes/https_helper.php';
epassport_require_https_for_gps();

require_once '../config/database.php';

$db = new Database();
$conn = $db->connect();

$stmt = $conn->prepare("
    SELECT user_id, student_no, firstname, lastname
    FROM users
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Student not found.');
}

$studentName = trim($student['firstname'] . ' ' . $student['lastname']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1f2f88">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Background Monitoring | E-PASSPORT</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap-icons/bootstrap-icons.css')) ?>">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="stylesheet" href="../assets/css/student.css">
    <style>
        .monitor-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .monitor-card {
            max-width: 520px;
            width: 100%;
            border-radius: 1rem;
            box-shadow: 0 20px 50px rgba(23, 35, 103, 0.18);
            overflow: hidden;
        }
        .monitor-pulse {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: rgba(25, 135, 84, 0.15);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            animation: monitorPulse 2s ease-in-out infinite;
        }
        @keyframes monitorPulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.06); opacity: 0.85; }
        }
        .monitor-status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.4rem;
            background: #198754;
        }
    </style>
</head>
<body class="student-body">
<div class="monitor-shell">
    <div class="monitor-card bg-white">
        <div class="bg-primary text-white p-4 text-center">
            <div class="monitor-pulse mb-3">
                <i class="bi bi-broadcast-pin text-success" style="font-size: 2rem;"></i>
            </div>
            <h1 class="h4 mb-1">Background Monitoring Active</h1>
            <p class="small mb-0 opacity-75"><?= htmlspecialchars($studentName) ?> · <?= htmlspecialchars($student['student_no']) ?></p>
        </div>

        <div class="p-4">
            <div id="monitorStatus" class="alert alert-info small mb-3">
                <span class="monitor-status-dot"></span>
                Starting location monitoring…
            </div>

            <ul class="small text-muted mb-4">
                <li class="mb-2">Keep this page <strong>open in your browser</strong>. Switching to Messenger, Facebook, or the Home screen usually <strong>pauses GPS</strong>.</li>
                <li class="mb-2">Keep <strong>Location Services</strong> and <strong>Wi-Fi or mobile data</strong> turned ON.</li>
                <li class="mb-2">Do not swipe Chrome/Safari away from recent apps until the event logout scan.</li>
                <li>On Android you may add this site to your <strong>Home screen</strong> for a slightly more stable tab — it is still a website, not a store app.</li>
            </ul>

            <div class="d-grid gap-2">
                <a href="dashboard.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-qr-code"></i> Back to QR Dashboard
                </a>
                <a href="../logout.php" class="btn btn-outline-secondary btn-sm">Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/background-monitor.js?v=1"></script>
<script src="../assets/js/location-bridge.js?v=21"></script>
<script src="../assets/js/gps.js?v=22"></script>
<?php
require_once __DIR__ . '/../includes/background_jobs.php';
epassport_render_background_jobs_script();
?>
<script>
    function setMonitorStatus(type, html) {
        const el = document.getElementById('monitorStatus');
        if (!el) return;
        el.className = 'alert alert-' + type + ' small mb-3';
        el.innerHTML = '<span class="monitor-status-dot"></span>' + html;
    }

    const originalUpdateGPSUI = window.updateGPSUI;
    window.updateGPSUI = function (type, message) {
        setMonitorStatus(type, message);
        if (typeof originalUpdateGPSUI === 'function') {
            originalUpdateGPSUI(type, message);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        resumeMonitoringIfNeeded();
    });
</script>
</body>
</html>
