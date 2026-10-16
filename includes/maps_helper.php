<?php

function googleMapsApiKey(): string
{
    if (!function_exists('epassport_env')) {
        require_once __DIR__ . '/env.php';
    }

    $key = trim(epassport_env('EPASSPORT_GOOGLE_MAPS_API_KEY'));

    if ($key === '' && function_exists('appConfig')) {
        $key = trim((string) (appConfig()['google_maps_api_key'] ?? ''));
    }

    return $key;
}

function googleMapsMapId(): string
{
    if (!function_exists('epassport_env')) {
        require_once __DIR__ . '/env.php';
    }

    $mapId = trim(epassport_env('EPASSPORT_GOOGLE_MAPS_MAP_ID'));

    if ($mapId === '' && function_exists('appConfig')) {
        $mapId = trim((string) (appConfig()['google_maps_map_id'] ?? ''));
    }

    return $mapId;
}

function googleMapsHelperScript(string $assetsBase = '../assets'): string
{
    $scriptBase = rtrim($assetsBase, '/');

    return '<script src="' . htmlspecialchars($scriptBase . '/js/admin-maps.js') . '"></script>';
}

function googleMapsApiScript(string $callback, string $assetsBase = '../assets'): string
{
    $key = googleMapsApiKey();

    if ($key === '') {
        return '<div class="alert alert-danger">Google Maps API key is not configured (EPASSPORT_GOOGLE_MAPS_API_KEY in .env).</div>';
    }

    $params = [
        'key' => $key,
        'callback' => $callback,
        'loading' => 'async',
    ];

    $mapId = googleMapsMapId();
    if ($mapId !== '' && strtoupper($mapId) !== 'DEMO_MAP_ID') {
        $params['libraries'] = 'marker';
    }

    $url = 'https://maps.googleapis.com/maps/api/js?' . http_build_query($params);

    return '<script async defer src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
}

function googleMapsAssets(string $callback, string $assetsBase = '../assets'): string
{
    return googleMapsHelperScript($assetsBase) . googleMapsApiScript($callback, $assetsBase);
}

/**
 * Default map options — starts at campus center, no bounds lock (admin can pan/zoom freely).
 *
 * @param array<string, mixed> $campus
 * @return array<string, mixed>
 */
function googleMapsDefaultOptions(array $campus): array
{
    $mapId = googleMapsMapId();

    $options = [
        'center' => [
            'lat' => (float) ($campus['latitude'] ?? 8.38485),
            'lng' => (float) ($campus['longitude'] ?? 123.80435),
        ],
        'zoom' => (int) ($campus['default_zoom'] ?? 17),
        'minZoom' => 3,
        'maxZoom' => 22,
        'mapTypeId' => 'hybrid',
        'mapTypeControl' => true,
        'streetViewControl' => true,
        'fullscreenControl' => true,
        'zoomControl' => true,
        'gestureHandling' => 'greedy',
        'clickableIcons' => true,
        'draggable' => true,
        'scrollwheel' => true,
        'disableDoubleClickZoom' => false,
    ];

    if ($mapId !== '' && strtoupper($mapId) !== 'DEMO_MAP_ID') {
        $options['mapId'] = $mapId;
    }

    return $options;
}
