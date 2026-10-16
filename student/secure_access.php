<?php
require_once '../includes/https_helper.php';
require_once '../includes/bootstrap.php';

$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$httpsUrl = 'https://' . $host . epassport_url('student/dashboard.php');
$isHttps = epassport_is_https_request();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure access — E-PASSPORT</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap/bootstrap.min.css')) ?>">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 520px;">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h5 mb-3">GPS needs a secure connection</h1>

            <?php if ($isHttps): ?>
                <div class="alert alert-success small">
                    You are on HTTPS. If you already tapped <strong>Advanced → Proceed</strong> on the
                    privacy warning, you can continue to the dashboard.
                </div>
                <a href="dashboard.php" class="btn btn-primary w-100">Open student dashboard</a>
            <?php else: ?>
                <p class="small text-muted">
                    Phones block GPS on plain <code>http://</code>. Use HTTPS on the same Wi‑Fi network.
                </p>

                <div class="alert alert-warning small">
                    <strong>After tapping the button below</strong>, Chrome will show
                    <em>“Your connection is not private”</em> (<code>NET::ERR_CERT_AUTHORITY_INVALID</code>).
                    That is normal for a local server.
                    <ol class="mb-0 mt-2 ps-3">
                        <li>Tap <strong>Advanced</strong></li>
                        <li>Tap <strong>Proceed to <?= htmlspecialchars($host) ?> (unsafe)</strong></li>
                        <li>Log in and allow <strong>Location</strong> when asked</li>
                    </ol>
                </div>

                <a href="<?= htmlspecialchars($httpsUrl) ?>" class="btn btn-primary w-100 mb-2">
                    Continue with HTTPS
                </a>

                <p class="small text-muted mb-0">
                    Server PC: run <code>setup\generate-lan-ssl.ps1</code> once so the certificate matches
                    your IP (<code><?= htmlspecialchars($host) ?></code>).
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
