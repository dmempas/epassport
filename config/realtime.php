<?php



return [

    'enabled' => true,

    // Start realtime/server.js automatically when someone opens the website (if Node is installed).
    'autostart_websocket' => true,

    // Port for the Node WebSocket server (realtime/server.js)
    'ws_port' => 8081,

    // Leave empty to auto-use the same host as the PHP site (works on LAN phones).
    // Example for manual override: '192.168.1.10' or 'epassport.yourschool.edu'
    'ws_public_host' => '',

    // When using HTTPS, set this path and proxy it in Apache/Nginx to ws://127.0.0.1:8081
    // Example: 'epassport-ws' → students connect to wss://yourdomain.com/E-PASSPORT/epassport-ws
    // See setup/WEB-DEPLOYMENT.md and setup/apache-ws-proxy.conf
    'ws_path' => '',

    // Optional full override (e.g. 'wss://epassport.yourschool.edu/epassport-ws')
    'ws_url_client' => '',

    // PHP → Node push (always localhost — both run on the same machine)
    'notify_url' => 'http://127.0.0.1:8081/notify',

    // Student dashboard polling when WebSocket is connected vs offline (seconds)
    'poll_interval_ws_online' => 10,
    'poll_interval_ws_offline' => 2,

    // Admin Live Student Tracking map refresh (seconds)
    'live_tracking_poll_seconds' => 12,

    'location_grace_seconds' => 300,

    // Max meters added to geofence radius when GPS accuracy is poor (reduces false "outside").
    'geofence_accuracy_buffer_max' => 40,

    // Consecutive missed GPS reports before location-off violation during monitoring.
    'monitoring_missed_pings_before_violation' => 5,

    // Seconds without valid GPS before server treats location as unavailable.
    'monitoring_gps_stale_seconds' => 120,

    // No location upload (airplane mode / no data): SMS warning, then auto-penalty.
    'monitoring_silent_warning_seconds' => 900,
    'monitoring_silent_violation_seconds' => 1800,

    // GPS filtering & hysteresis (reduces boundary flicker and false positives)
    'gps_history_size' => 5,
    'geofence_entry_buffer_m' => 15,
    'geofence_exit_buffer_m' => 15,
    'geofence_consistency_threshold' => 3,

    // Flag a new point that is at least this far from the last accepted fix
    // and implies faster travel than this speed. Shorter gaps are GPS noise.
    // The attendance stamp is not changed; Live Student Tracking shows the flag.
    'gps_jump_min_distance_m' => 500,
    'gps_jump_max_speed_kmh' => 120,

];


