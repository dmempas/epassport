<?php

function renderStudentNav(string $activePage = 'dashboard'): void
{
    $items = [
        'dashboard' => ['href' => 'dashboard.php', 'label' => 'Home', 'title' => 'Dashboard', 'icon' => 'bi-house'],
        'activity' => ['href' => 'activity_log.php', 'label' => 'Log', 'title' => 'Activity Log', 'icon' => 'bi-clock-history'],
        'attendance' => ['href' => 'attendance.php', 'label' => 'Attend', 'title' => 'Attendance', 'icon' => 'bi-clipboard-check'],
        'penalties' => ['href' => 'penalties.php', 'label' => 'Penalty', 'title' => 'Penalties', 'icon' => 'bi-exclamation-triangle'],
        'profile' => ['href' => 'profile.php', 'label' => 'Profile', 'title' => 'Profile', 'icon' => 'bi-person'],
    ];
    ?>
    <nav class="portal-tabs portal-tabs--student" aria-label="Student navigation">
        <?php foreach ($items as $key => $item): ?>
            <a href="<?= htmlspecialchars($item['href']) ?>"
               class="portal-tabs__link<?= $key === $activePage ? ' is-active' : '' ?>"
               title="<?= htmlspecialchars($item['title']) ?>">
                <i class="bi <?= htmlspecialchars($item['icon']) ?>"></i>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
        <a href="../logout.php" class="portal-tabs__link portal-tabs__link--logout" title="Logout">
            <i class="bi bi-box-arrow-right"></i>
            <span>Out</span>
        </a>
    </nav>
    <?php
}
