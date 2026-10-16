<?php

require_once __DIR__ . '/../includes/bootstrap.php';

class Database
{
    public function connect()
    {
        $host = epassport_env('EPASSPORT_DB_HOST', 'localhost');
        $dbname = epassport_env('EPASSPORT_DB_NAME', 'e_passport');
        $username = epassport_env('EPASSPORT_DB_USER', 'root');
        $password = epassport_env('EPASSPORT_DB_PASSWORD', '');

        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $username,
                $password
            );

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            configureAppClock($pdo);

            if (PHP_SAPI !== 'cli') {
                try {
                    require_once __DIR__ . '/../includes/realtime_autostart.php';
                    ensureEpassportWebsocketServer();
                } catch (Throwable $e) {
                    // WebSocket autostart must not block page load.
                }

                try {
                    require_once __DIR__ . '/../includes/background_jobs.php';
                    runEpassportBackgroundJobs($pdo);
                    runEpassportAttendanceFallback($pdo);
                } catch (Throwable $e) {
                    // Background jobs must not block page load.
                }
            }

            return $pdo;
        } catch (PDOException $e) {
            die("Database Connection Failed: " . $e->getMessage());
        }
    }
}
