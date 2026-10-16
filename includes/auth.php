<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit_helpers.php';
require_once __DIR__ . '/login_throttle.php';

class Auth {

    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
    }

    public function login($student_no, $password) {

        $student_no = epassport_normalize_login_student_no((string) $student_no);

        $throttle = epassport_login_throttle_status($this->conn, $student_no);

        if ($throttle['locked']) {
            return ['status' => false, 'message' => $throttle['message']];
        }

        $stmt = $this->conn->prepare("
            SELECT * FROM users 
            WHERE student_no = :student_no AND is_active = 1
        ");

        $stmt->bindParam(':student_no', $student_no);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $invalidLogin = 'Invalid student number or password.';

        if (!$user) {
            $fail = epassport_login_throttle_fail($this->conn, $student_no);

            return [
                'status' => false,
                'message' => $fail['locked'] ? $fail['message'] : $invalidLogin,
            ];
        }

        if (!password_verify((string) $password, $user['password'])) {
            $fail = epassport_login_throttle_fail($this->conn, $student_no);
            logAudit($this->conn, 'login_failed', 'users', (int) $user['user_id'], 'Failed sign-in', (int) $user['user_id']);

            return [
                'status' => false,
                'message' => $fail['locked'] ? $fail['message'] : $invalidLogin,
            ];
        }

        epassport_login_throttle_clear($this->conn, $student_no);

        // SESSION DATA
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['name'] = $user['firstname'];

        logAudit(
            $this->conn,
            'login_success',
            'users',
            (int) $user['user_id'],
            auditRoleLabel((int) $user['role_id']) . ' login',
            (int) $user['user_id']
        );

        return [
            'status' => true,
            'must_change_password' => $user['must_change_password'],
            'role_id' => $user['role_id']
        ];
    }
}