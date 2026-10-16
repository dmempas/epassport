<?php

/**
 * Reset a student account password to the default (their student number).
 * Used by SSC officers when a student forgets their password.
 */
function resetStudentPasswordToDefault(PDO $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT user_id, student_no, firstname, lastname, role_id, is_active
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }

    if ((int) $user['role_id'] !== 2) {
        return ['ok' => false, 'message' => 'Only student accounts can be reset through this tool.'];
    }

    if ((int) $user['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'This student account is inactive. Contact an administrator.'];
    }

    $studentNo = trim((string) ($user['student_no'] ?? ''));

    if ($studentNo === '') {
        return ['ok' => false, 'message' => 'Student has no student number on file.'];
    }

    $hashed = password_hash($studentNo, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        UPDATE users
        SET password = ?,
            must_change_password = 1
        WHERE user_id = ?
    ");
    $stmt->execute([$hashed, $userId]);

    require_once __DIR__ . '/audit_helpers.php';
    logAudit(
        $conn,
        'password_reset',
        'users',
        $userId,
        'Reset password to default for ' . trim($user['firstname'] . ' ' . $user['lastname']) . ' (' . $studentNo . ')'
    );

    return [
        'ok' => true,
        'message' => 'Password reset to default (student number). The student must change it on next login.',
        'student_no' => $studentNo,
        'student_name' => trim($user['firstname'] . ' ' . $user['lastname']),
    ];
}

function findStudentByStudentNo(PDO $conn, string $studentNo): ?array
{
    $t = trim($studentNo);

    if ($t === '') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT user_id, student_no, firstname, lastname, course, year_level, is_active
        FROM users
        WHERE role_id = 2
        AND (
            student_no = ?
            OR TRIM(student_no) = TRIM(?)
            OR LOWER(TRIM(student_no)) = LOWER(TRIM(?))
        )
        LIMIT 1
    ");
    $stmt->execute([$t, $t, $t]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}
