<?php

require_once __DIR__ . '/audit_helpers.php';

/**
 * Password change validation and persistence.
 */
function fetchUserForPasswordChange(PDO $conn, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT user_id, role_id, student_no, password, must_change_password, is_active
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int) $user['is_active'] !== 1) {
        return null;
    }

    return $user;
}

function validatePasswordChangeInput(
    array $user,
    string $currentPassword,
    string $newPassword,
    string $confirmPassword,
    bool $forcedChange
): ?string {
    if (!$forcedChange) {
        if ($currentPassword === '') {
            return 'Enter your current password.';
        }

        if (!password_verify($currentPassword, $user['password'])) {
            return 'Current password is incorrect.';
        }
    }

    if ($newPassword === '') {
        return 'Enter a new password.';
    }

    $strengthError = epassport_validate_strong_password($newPassword);

    if ($strengthError !== null) {
        return $strengthError;
    }

    if ($newPassword !== $confirmPassword) {
        return 'Passwords do not match.';
    }

    $studentNo = trim((string) ($user['student_no'] ?? ''));

    if ($studentNo !== '' && hash_equals($studentNo, $newPassword)) {
        return 'Password cannot be your student number.';
    }

    if (password_verify($newPassword, $user['password'])) {
        return 'New password must be different from your current password.';
    }

    return null;
}

function processPasswordChange(
    PDO $conn,
    array $user,
    string $currentPassword,
    string $newPassword,
    string $confirmPassword,
    bool $forcedChange
): array {
    $error = validatePasswordChangeInput(
        $user,
        $currentPassword,
        $newPassword,
        $confirmPassword,
        $forcedChange
    );

    if ($error !== null) {
        return ['ok' => false, 'message' => $error];
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        UPDATE users
        SET password = ?,
            must_change_password = 0
        WHERE user_id = ?
    ");
    $stmt->execute([$hashedPassword, (int) $user['user_id']]);

    logAudit(
        $conn,
        'password_changed',
        'users',
        (int) $user['user_id'],
        auditRoleLabel((int) $user['role_id']) . ($forcedChange ? ' forced password change' : ' voluntary password change'),
        (int) $user['user_id']
    );

    return ['ok' => true, 'message' => 'Your password has been updated successfully.'];
}

function epassport_password_is_repeated_character(string $password): bool
{
    return $password !== '' && preg_match('/^(.)\1+$/u', $password) === 1;
}

function epassport_password_has_sequential_run(string $password, int $runLength = 4): bool
{
    $normalized = strtolower($password);
    $length = strlen($normalized);

    if ($length < $runLength) {
        return false;
    }

    $isSequential = static function (string $chunk, int $step): bool {
        $n = strlen($chunk);

        for ($i = 1; $i < $n; $i++) {
            if (ord($chunk[$i]) !== ord($chunk[$i - 1]) + $step) {
                return false;
            }
        }

        return true;
    };

    for ($i = 0; $i <= $length - $runLength; $i++) {
        $chunk = substr($normalized, $i, $runLength);

        if (ctype_digit($chunk) && ($isSequential($chunk, 1) || $isSequential($chunk, -1))) {
            return true;
        }

        if (ctype_alpha($chunk) && ($isSequential($chunk, 1) || $isSequential($chunk, -1))) {
            return true;
        }
    }

    return false;
}

function epassport_password_is_common_weak(string $password): bool
{
    $blocked = [
        '12345678',
        '87654321',
        '123456789',
        '987654321',
        '01234567',
        '00000000',
        '0000000000',
        '11111111',
        '11111111111',
        'password',
        'password1',
        'password123',
        'qwerty',
        'qwerty123',
        'admin123',
        'letmein',
        'iloveyou',
        'abc12345',
        'abcdefgh',
    ];

    return in_array(strtolower($password), $blocked, true);
}

function epassport_validate_strong_password(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }

    if (
        !preg_match('/[A-Za-z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        return 'Password must include letters, numbers, and symbols.';
    }

    if (epassport_password_is_repeated_character($password)) {
        return 'Password cannot be the same character repeated (for example 00000000 or 11111111).';
    }

    if (epassport_password_has_sequential_run($password, 4)) {
        return 'Password cannot contain a simple sequence such as 1234, 8765, or abcd.';
    }

    if (epassport_password_is_common_weak($password)) {
        return 'That password is too common. Choose a stronger mix of letters, numbers, and symbols.';
    }

    return null;
}
