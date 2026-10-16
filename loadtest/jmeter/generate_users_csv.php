<?php

/**
 * Writes TEST001–TEST2000 for JMeter (password = student number).
 *
 *   C:\xampp\php\php.exe generate_users_csv.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from Command Prompt.\n");
}

$out = __DIR__ . DIRECTORY_SEPARATOR . 'users.csv';
$handle = fopen($out, 'wb');

if ($handle === false) {
    fwrite(STDERR, "Could not write {$out}\n");
    exit(1);
}

fputcsv($handle, ['student_no', 'password']);

for ($i = 1; $i <= 2000; $i++) {
    $studentNo = 'TEST' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    fputcsv($handle, [$studentNo, $studentNo]);
}

fclose($handle);
echo "Wrote 2000 rows to {$out}\n";
