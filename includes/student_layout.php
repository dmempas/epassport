<?php

require_once __DIR__ . '/student_nav.php';
require_once __DIR__ . '/background_jobs.php';

function renderStudentHeader(
    string $documentTitle,
    string $activePage,
    string $portalLabel = 'Student Portal'
): void {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <meta name="theme-color" content="#1f2f88">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <title><?= htmlspecialchars($documentTitle) ?> | E-PASSPORT</title>
        <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap/bootstrap.min.css')) ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap-icons/bootstrap-icons.css')) ?>">
        <link rel="manifest" href="manifest.webmanifest">
        <link rel="stylesheet" href="../assets/css/student.css?v=25">
    </head>
    <body class="student-body">
    <div class="student-shell">
        <header class="student-nav">
            <div class="student-brand">
                E-PASSPORT
                <small><?= htmlspecialchars($portalLabel) ?></small>
            </div>
            <?php renderStudentNav($activePage); ?>
        </header>
        <main class="student-main">
    <?php
}

function renderStudentFooter(): void
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
