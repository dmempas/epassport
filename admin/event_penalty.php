<?php
require_once '../includes/session.php';
requireRole(1);

$event_id = (int) ($_GET['event_id'] ?? 0);

if ($event_id <= 0) {
    header('Location: events.php');
    exit;
}

header("Location: event_setup.php?event_id={$event_id}&step=4");
exit;
