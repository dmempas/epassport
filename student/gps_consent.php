<?php

require_once '../includes/session.php';
requireRole(2, false);

require_once '../config/database.php';
require_once '../includes/gps_consent.php';

if (epassport_student_gps_consent_ok()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agreed = isset($_POST['agree']) && (string) $_POST['agree'] === '1';

    if (!$agreed) {
        $error = 'You must give consent before you can open the student portal.';
    } else {
        $db = new Database();
        $conn = $db->connect();
        epassport_record_gps_consent($conn, (int) $_SESSION['user_id']);
        header('Location: dashboard.php');
        exit;
    }
}

$buttonLabel = EPASSPORT_GPS_CONSENT_BUTTON;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Location Consent Notice | E-PASSPORT</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="../assets/css/login.css">
    <style>
        .consent-shell .login-panel {
            align-items: flex-start;
            overflow-y: auto;
        }
        .consent-shell .panel-content {
            max-width: 560px;
            padding: 1.25rem 0 2rem;
        }
        .consent-kicker {
            font-size: 0.75rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #8b91ad;
            margin: 0 0 0.35rem;
            text-align: center;
        }
        .consent-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #1a1c72;
            text-align: center;
            margin: 0 0 0.35rem;
            line-height: 1.25;
        }
        .consent-subtitle {
            font-size: 1.02rem;
            font-weight: 600;
            color: #24379e;
            text-align: center;
            margin: 0 0 1.1rem;
        }
        .consent-copy {
            font-size: 0.9rem;
            line-height: 1.5;
            color: #3d4466;
            margin: 0 0 0.9rem;
        }
        .consent-list {
            margin: 0 0 0.9rem;
            padding-left: 1.15rem;
            color: #3d4466;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .consent-list li {
            margin-bottom: 0.45rem;
        }
        .consent-btn {
            white-space: normal;
            line-height: 1.4;
            padding: 0.85rem 0.9rem;
            font-size: 0.92rem;
        }
    </style>
</head>
<body>
<div class="login-shell consent-shell">
    <section class="login-panel">
        <div class="panel-content">
            <div class="brand-wrap">
                <div class="brand-main">E-<span>Passport</span></div>
                <div class="brand-sub">USTP Panaon Campus</div>
            </div>
            <p class="consent-kicker">Required every time you sign in</p>
            <h1 class="consent-title">GPS Location Consent Notice</h1>
            <h2 class="consent-subtitle">GPS Location Access Consent</h2>

            <p class="consent-copy">
                The E-PASSPORT: USTP Panaon Campus Student Monitoring System requires access to your device's location services to support attendance validation, campus-restricted monitoring, and compliance with university event participation policies.
            </p>
            <p class="consent-copy">
                By logging in to and using this system, you acknowledge and consent to the collection and processing of your real-time GPS location data during authorized university activities and events. Your location data is collected solely for the following purposes:
            </p>
            <ul class="consent-list">
                <li>To verify your presence within the designated USTP Panaon Campus or official event venue geofence during authorized events and activities.</li>
                <li>To validate attendance records and prevent unauthorized or proxy attendance.</li>
                <li>To support real-time campus monitoring and ensure compliance with institutional policies.</li>
                <li>To enable automated notifications, warnings, and penalty processing when monitoring rules are violated.</li>
                <li>To maintain accurate attendance, monitoring, and audit records for administrative and security purposes.</li>
            </ul>
            <p class="consent-copy">
                The E-PASSPORT system uses location data only in connection with official university monitoring activities and does not collect location information for purposes unrelated to attendance validation, campus safety, or policy enforcement. Location data is handled in accordance with applicable data privacy regulations and university policies and is accessible only to authorized personnel for legitimate administrative purposes.
            </p>
            <p class="consent-copy">
                If location access is denied, certain system features, including attendance validation and campus monitoring services, may not function properly, and attendance verification may be affected.
            </p>
            <p class="consent-copy">
                By selecting &ldquo;<?= htmlspecialchars($buttonLabel) ?>&rdquo; and continuing to use the E-PASSPORT system, you confirm that you have read, understood, and agreed to this GPS Location Access Consent Notice.
            </p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2 px-3 small"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="agree" value="1">
                <button type="submit" class="btn btn-login consent-btn">
                    <?= htmlspecialchars($buttonLabel) ?>
                </button>
            </form>
            <p class="text-center mt-3 mb-0">
                <a href="../logout.php" class="small text-decoration-none" style="color: var(--brand-blue-soft);">
                    Sign out
                </a>
            </p>
        </div>
    </section>
    <aside class="campus-panel" aria-hidden="true">
        <div class="campus-caption">
            <h2>USTP Panaon Campus</h2>
            <p>Location data is used for official event attendance and campus monitoring.</p>
        </div>
    </aside>
</div>
</body>
</html>
