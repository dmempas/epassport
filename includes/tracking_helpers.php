<?php

require_once __DIR__ . '/monitoring_helpers.php';

/**
 * Build inside/outside activity segments from ordered location logs.
 *
 * @param array<int, array<string, mixed>> $logs
 * @return array<int, array<string, mixed>>
 */
function buildTrackingSegments(array $logs): array
{
    if (!$logs) {
        return [];
    }

    $segments = [];
    $current = null;

    foreach ($logs as $log) {
        $status = resolveTrackingStatus($log);
        $loggedAt = (string) ($log['logged_at'] ?? '');
        $lat = isset($log['latitude']) ? (float) $log['latitude'] : null;
        $lng = isset($log['longitude']) ? (float) $log['longitude'] : null;

        if ($current === null) {
            $current = [
                'status' => $status,
                'start_at' => $loggedAt,
                'end_at' => $loggedAt,
                'start_lat' => $lat,
                'start_lng' => $lng,
                'end_lat' => $lat,
                'end_lng' => $lng,
                'ping_count' => 1,
            ];
            continue;
        }

        if ($current['status'] === $status) {
            $current['end_at'] = $loggedAt;
            $current['end_lat'] = $lat;
            $current['end_lng'] = $lng;
            $current['ping_count']++;
            continue;
        }

        $segments[] = finalizeTrackingSegment($current);
        $current = [
            'status' => $status,
            'start_at' => $loggedAt,
            'end_at' => $loggedAt,
            'start_lat' => $lat,
            'start_lng' => $lng,
            'end_lat' => $lat,
            'end_lng' => $lng,
            'ping_count' => 1,
        ];
    }

    if ($current !== null) {
        $segments[] = finalizeTrackingSegment($current);
    }

    return $segments;
}

function resolveTrackingStatus(array $log): string
{
    $locationStatus = (string) ($log['location_status'] ?? '');

    if ($locationStatus === 'unavailable' || $locationStatus === 'denied') {
        return 'gps_unavailable';
    }

    if ((int) ($log['is_inside_geofence'] ?? 0) === 1) {
        return 'inside';
    }

    if ($locationStatus === 'outside' || (int) ($log['is_inside_geofence'] ?? 0) === 0) {
        return 'outside';
    }

    return 'unknown';
}

/**
 * @param array<string, mixed> $segment
 * @return array<string, mixed>
 */
function finalizeTrackingSegment(array $segment): array
{
    $startTs = strtotime((string) $segment['start_at']);
    $endTs = strtotime((string) $segment['end_at']);
    $durationSeconds = ($startTs && $endTs && $endTs >= $startTs) ? ($endTs - $startTs) : 0;

    $segment['duration_seconds'] = $durationSeconds;
    $segment['duration_label'] = formatTrackingDuration($durationSeconds);
    $segment['status_label'] = trackingStatusLabel((string) $segment['status']);

    return $segment;
}

function trackingStatusLabel(string $status): string
{
    return match ($status) {
        'inside' => 'Inside geofence',
        'outside' => 'Outside geofence',
        'gps_unavailable' => 'GPS unavailable',
        default => 'Unknown',
    };
}

function formatTrackingDuration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }

    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;

    if ($minutes < 60) {
        return $remaining > 0 ? "{$minutes}m {$remaining}s" : "{$minutes}m";
    }

    $hours = intdiv($minutes, 60);
    $minutes = $minutes % 60;

    return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
}

function formatTrackingDistance(float $meters): string
{
    if ($meters < 1000) {
        return number_format($meters, 0) . ' m';
    }

    return number_format($meters / 1000, 2) . ' km';
}

/**
 * @param array<int, array<string, mixed>> $logs
 * @param array<int, array<string, mixed>> $segments
 * @return array<string, mixed>
 */
function computeTrackingSummary(array $logs, array $segments): array
{
    $insideSeconds = 0;
    $outsideSeconds = 0;
    $unavailableSeconds = 0;
    $distanceMeters = 0.0;
    $prev = null;

    foreach ($segments as $segment) {
        $seconds = (int) ($segment['duration_seconds'] ?? 0);

        if ($segment['status'] === 'inside') {
            $insideSeconds += $seconds;
        } elseif ($segment['status'] === 'outside') {
            $outsideSeconds += $seconds;
        } elseif ($segment['status'] === 'gps_unavailable') {
            $unavailableSeconds += $seconds;
        }
    }

    foreach ($logs as $log) {
        $lat = isset($log['latitude']) ? (float) $log['latitude'] : 0.0;
        $lng = isset($log['longitude']) ? (float) $log['longitude'] : 0.0;

        if (!$lat || !$lng || abs($lat) > 90 || abs($lng) > 180) {
            continue;
        }

        if ($prev !== null) {
            $distanceMeters += haversineDistanceMeters(
                $prev['lat'],
                $prev['lng'],
                $lat,
                $lng
            );
        }

        $prev = ['lat' => $lat, 'lng' => $lng];
    }

    $firstAt = $logs[0]['logged_at'] ?? null;
    $lastAt = $logs[count($logs) - 1]['logged_at'] ?? null;
    $sessionSeconds = 0;

    if ($firstAt && $lastAt) {
        $startTs = strtotime((string) $firstAt);
        $endTs = strtotime((string) $lastAt);
        if ($startTs && $endTs && $endTs >= $startTs) {
            $sessionSeconds = $endTs - $startTs;
        }
    }

    return [
        'ping_count' => count($logs),
        'segment_count' => count($segments),
        'distance_m' => round($distanceMeters, 1),
        'distance_label' => formatTrackingDistance($distanceMeters),
        'inside_seconds' => $insideSeconds,
        'inside_label' => formatTrackingDuration($insideSeconds),
        'outside_seconds' => $outsideSeconds,
        'outside_label' => formatTrackingDuration($outsideSeconds),
        'unavailable_seconds' => $unavailableSeconds,
        'unavailable_label' => formatTrackingDuration($unavailableSeconds),
        'session_seconds' => $sessionSeconds,
        'session_label' => formatTrackingDuration($sessionSeconds),
        'first_ping_at' => $firstAt,
        'last_ping_at' => $lastAt,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function fetchStudentTrackingDetail(PDO $conn, int $attendanceId, ?string $dateFrom = null, ?string $dateTo = null): ?array
{
    $stmt = $conn->prepare("
        SELECT
            a.attendance_id,
            a.user_id,
            a.event_id,
            a.scan_type,
            a.status AS attendance_status,
            a.qr_scanned_at,
            u.firstname,
            u.lastname,
            u.student_no,
            e.event_name
        FROM attendance_sessions a
        INNER JOIN users u ON a.user_id = u.user_id
        INNER JOIN events e ON a.event_id = e.event_id
        WHERE a.attendance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$attendanceId]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attendance) {
        return null;
    }

    $params = [$attendanceId];
    $dateFilter = '';

    if ($dateFrom !== null && $dateFrom !== '') {
        $dateFilter .= ' AND logged_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== null && $dateTo !== '') {
        $dateFilter .= ' AND logged_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    $stmt = $conn->prepare("
        SELECT
            location_id,
            latitude,
            longitude,
            accuracy,
            is_inside_geofence,
            location_status,
            logged_at
        FROM location_logs
        WHERE attendance_id = ?
          AND latitude IS NOT NULL
          AND longitude IS NOT NULL
          AND latitude != 0
          AND longitude != 0
          {$dateFilter}
        ORDER BY logged_at ASC, location_id ASC
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $segments = buildTrackingSegments($logs);
    $summary = computeTrackingSummary($logs, $segments);

    $path = [];
    $waypointIndex = 1;

    foreach ($logs as $log) {
        $path[] = [
            'index' => $waypointIndex++,
            'lat' => (float) $log['latitude'],
            'lng' => (float) $log['longitude'],
            'accuracy' => isset($log['accuracy']) ? (float) $log['accuracy'] : null,
            'is_inside_geofence' => (int) ($log['is_inside_geofence'] ?? 0),
            'location_status' => (string) ($log['location_status'] ?? ''),
            'logged_at' => (string) $log['logged_at'],
        ];
    }

    $segmentRows = [];
    foreach ($segments as $i => $segment) {
        $segmentRows[] = [
            'sequence' => $i + 1,
            'status' => (string) $segment['status'],
            'status_label' => (string) $segment['status_label'],
            'start_at' => (string) $segment['start_at'],
            'end_at' => (string) $segment['end_at'],
            'duration_seconds' => (int) $segment['duration_seconds'],
            'duration_label' => (string) $segment['duration_label'],
            'start_lat' => $segment['start_lat'],
            'start_lng' => $segment['start_lng'],
            'end_lat' => $segment['end_lat'],
            'end_lng' => $segment['end_lng'],
            'ping_count' => (int) $segment['ping_count'],
        ];
    }

    return [
        'attendance' => $attendance,
        'path' => $path,
        'segments' => $segmentRows,
        'summary' => $summary,
    ];
}
