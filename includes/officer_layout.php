<?php

require_once __DIR__ . '/officer_nav.php';
require_once __DIR__ . '/background_jobs.php';

function renderOfficerHeader(
    string $documentTitle,
    string $activePage,
    ?string $heading = null,
    ?string $subtitle = null
): void {
    $heading = $heading ?? 'Officer Dashboard';
    $subtitle = $subtitle ?? 'Scan student QR codes during active events and open scan windows only.';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($documentTitle) ?> | E-PASSPORT</title>
        <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap/bootstrap.min.css')) ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap-icons/bootstrap-icons.css')) ?>">
        <link rel="stylesheet" href="../assets/css/officer.css?v=18">
    </head>
    <body class="officer-body">
    <div class="officer-container">
        <header class="officer-header">
            <div class="officer-header-top">
                <div class="officer-header-copy">
                    <p class="officer-kicker">E-PASSPORT · Attendance Officer</p>
                    <h1 class="officer-title"><?= htmlspecialchars($heading) ?></h1>
                    <?php if ($subtitle !== ''): ?>
                        <p class="officer-subtitle"><?= htmlspecialchars($subtitle) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php renderOfficerNav($activePage); ?>
        </header>
        <main class="officer-main">
    <?php
}

function renderOfficerFooter(): void
{
    ?>
        </main>
    </div>
    <?php epassport_render_dialog_script(); ?>
    <?php epassport_render_background_jobs_script(); ?>
    </body>
    </html>
    <?php
}
