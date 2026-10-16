-- Paste in phpMyAdmin with database e_passport selected, then click Go.
-- Adds per-day scan windows (different login/logout times on different dates).
-- Does not delete events, users, or attendance.
-- If a statement errors because a column/index already exists, skip that statement and continue.

USE `e_passport`;

ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `duration_days` DECIMAL(4,1) NOT NULL DEFAULT 1.0 AFTER `end_date`,
  ADD COLUMN IF NOT EXISTS `half_day_part` ENUM('am','pm') NOT NULL DEFAULT 'am' AFTER `duration_days`;

ALTER TABLE `event_scan_requirements`
  ADD COLUMN IF NOT EXISTS `scan_date` DATE NULL AFTER `event_id`;

UPDATE `event_scan_requirements` esr
INNER JOIN `events` e ON e.event_id = esr.event_id
SET esr.scan_date = e.start_date
WHERE esr.scan_date IS NULL
  AND e.start_date IS NOT NULL;

UPDATE `events`
SET `duration_days` = (DATEDIFF(`end_date`, `start_date`) + 1)
WHERE `start_date` IS NOT NULL
  AND `end_date` IS NOT NULL
  AND (`duration_days` IS NULL OR `duration_days` = 1.0)
  AND DATEDIFF(`end_date`, `start_date`) >= 0;

INSERT INTO `event_scan_requirements`
  (`event_id`, `scan_date`, `scan_type`, `start_time`, `end_time`, `is_required`)
SELECT
  d.event_id,
  d.dt,
  r.scan_type,
  r.start_time,
  r.end_time,
  r.is_required
FROM (
  WITH RECURSIVE dates AS (
    SELECT
      event_id,
      start_date AS dt,
      end_date AS last_dt
    FROM events
    WHERE start_date IS NOT NULL
      AND end_date IS NOT NULL
      AND end_date >= start_date
    UNION ALL
    SELECT
      event_id,
      DATE_ADD(dt, INTERVAL 1 DAY),
      last_dt
    FROM dates
    WHERE dt < last_dt
  )
  SELECT event_id, dt FROM dates
) d
INNER JOIN events e ON e.event_id = d.event_id
INNER JOIN event_scan_requirements r
  ON r.event_id = d.event_id
 AND r.scan_date = e.start_date
WHERE d.dt <> e.start_date
  AND NOT EXISTS (
    SELECT 1
    FROM event_scan_requirements x
    WHERE x.event_id = d.event_id
      AND x.scan_date = d.dt
      AND x.scan_type = r.scan_type
  );

-- The old unique key may already be gone (PHP auto-migrate). IF EXISTS avoids error #1091.
ALTER TABLE `event_scan_requirements`
  DROP INDEX IF EXISTS `uq_event_scan_type`;

ALTER TABLE `event_scan_requirements`
  ADD UNIQUE KEY `uq_event_scan_day_type` (`event_id`, `scan_date`, `scan_type`);

ALTER TABLE `event_scan_requirements`
  ADD KEY `idx_scan_event_date` (`event_id`, `scan_date`);
