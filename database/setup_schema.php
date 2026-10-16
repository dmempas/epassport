<?php
/**
 * Apply official schema upgrades on the connected database.
 * Run once per deploy (not on every page load):
 *   php database/setup_schema.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/attendance_schema.php';
require_once __DIR__ . '/../includes/audit_helpers.php';
require_once __DIR__ . '/../includes/penalty_helpers.php';
require_once __DIR__ . '/../includes/schema_solid.php';
require_once __DIR__ . '/../includes/schema_indexes.php';
require_once __DIR__ . '/../includes/login_throttle.php';
require_once __DIR__ . '/../includes/gps_consent.php';

$db = new Database();
$conn = $db->connect();

ensureAttendanceSchema($conn);
ensureAuditSchema($conn);
ensurePenaltyResolutionSchema($conn);
ensureSolidSchema($conn);
ensureDatabaseIndexes($conn);
epassport_ensure_login_throttle_table($conn);
epassport_ensure_gps_consent_table($conn);

echo "E-PASSPORT schema is up to date (schema_version 3).\n";
