<?php



require_once '../includes/session.php';

requireRole(2);



require_once '../config/database.php';

require_once '../includes/realtime_config.php';



header('Content-Type: application/json');



$db = new Database();

$conn = $db->connect();



$config = epassport_realtime_config();



try {



    $token = bin2hex(random_bytes(32));

    $userId = (int) $_SESSION['user_id'];



    $stmt = $conn->prepare("

        INSERT INTO student_presence (user_id, session_token, last_seen_at, ws_connected)

        VALUES (?, ?, NOW(), 0)

        ON DUPLICATE KEY UPDATE

            session_token = VALUES(session_token),

            last_seen_at = NOW()

    ");



    $stmt->execute([$userId, $token]);



    $_SESSION['presence_token'] = $token;



    echo json_encode([

        'success' => true,

        'token' => $token,

        'user_id' => $userId,

        'ws_url' => $config['ws_url_client'],

        'realtime_enabled' => !empty($config['enabled']),

    ]);



} catch (PDOException $e) {



    http_response_code(503);



    echo json_encode([

        'success' => false,

        'message' => 'Realtime tables missing. Import database/e_passport.sql on a fresh database.',

        'ws_url' => $config['ws_url_client'] ?? '',

    ]);



}


