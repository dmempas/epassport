<?php
require_once '../includes/session.php';
requireRole(1);

header('Location: event_setup.php?step=1');
exit;
