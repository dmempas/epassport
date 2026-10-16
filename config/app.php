<?php

return [
    // UST Panaon campus — all scan windows and event dates use this timezone
    'timezone' => 'Asia/Manila',
    'timezone_label' => 'Philippine Time (PHT)',
    // MySQL session offset on Linux/production (UTC system clock). On Windows XAMPP, auto uses SYSTEM.
    'timezone_offset' => '+08:00',
    'mysql_time_zone' => 'auto',
    // URL path prefix when app is not at domain root (e.g. /E-PASSPORT). Use '' if at https://yourdomain.com/
    'base_path' => '/E-PASSPORT',

    // Maps key comes from .env (EPASSPORT_GOOGLE_MAPS_API_KEY). Injected only into the Maps script URL.
    'google_maps_api_key' => epassport_env('EPASSPORT_GOOGLE_MAPS_API_KEY'),
    // Map ID for Advanced Markers — leave empty or set a real ID from Google Cloud Console.
    // DEMO_MAP_ID is ignored (uses classic interactive tiles instead).
    'google_maps_map_id' => epassport_env('EPASSPORT_GOOGLE_MAPS_MAP_ID'),

    // USTP Panaon campus — opening view frames USTP + Punta Meriam (admins can still pan/zoom freely)
    'campus' => [
        'name' => 'USTP Panaon Campus, Punta, Panaon, Misamis Occidental',
        'postal_code' => '7205',
        'latitude' => 8.38485,
        'longitude' => 123.80435,
        'map_bounds' => [
            'north' => 8.3885,
            'south' => 8.3810,
            'west' => 123.7985,
            'east' => 123.8110,
        ],
        // Used to fit the first map view so both landmarks are on screen.
        'focus_points' => [
            [
                'lat' => 8.3842,
                'lng' => 123.8015,
                'label' => 'USTP Panaon',
            ],
            [
                'lat' => 8.3855,
                'lng' => 123.8072,
                'label' => 'Punta Meriam',
            ],
        ],
        'default_radius' => 200,
        'default_zoom' => 17,
        'min_zoom' => 16,
        'max_opening_zoom' => 18,
    ],
];
