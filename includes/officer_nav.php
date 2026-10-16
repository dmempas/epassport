<?php

function renderOfficerNav(string $activePage = 'dashboard'): void
{
    $items = [
        'dashboard' => ['href' => 'dashboard.php', 'label' => 'Home', 'title' => 'Dashboard', 'icon' => 'bi-house'],
        'scanner' => ['href' => 'scanner.php', 'label' => 'Scan', 'title' => 'Scanner', 'icon' => 'bi-qr-code-scan'],
        'records' => ['href' => 'student_records.php', 'label' => 'Records', 'title' => 'Student records', 'icon' => 'bi-people'],
        'log' => ['href' => 'audit_log.php', 'label' => 'Log', 'title' => 'Audit log', 'icon' => 'bi-journal-text'],
    ];
    ?>
    <nav class="portal-tabs" aria-label="Officer navigation">
        <?php foreach ($items as $key => $item): ?>
            <a href="<?= htmlspecialchars($item['href']) ?>"
               class="portal-tabs__link<?= $key === $activePage ? ' is-active' : '' ?>"
               title="<?= htmlspecialchars($item['title']) ?>">
                <i class="bi <?= htmlspecialchars($item['icon']) ?>"></i>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
        <a href="../logout.php" class="portal-tabs__link" title="Logout">
            <i class="bi bi-box-arrow-right"></i>
            <span>Out</span>
        </a>
    </nav>
    <?php
}
