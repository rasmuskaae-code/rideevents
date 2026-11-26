<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
$name = trim($argv[2] ?? '');
if ($argc !== 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($name) > 120 || !preg_match('//u', $name)) {
    fwrite(STDERR, "Brug: php bin/admin.php e-mail navn. Send adgangskoden på stdin.\n");
    exit(1);
}
$password = rtrim(fgets(STDIN) ?: '', "\r\n");
if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
    fwrite(STDERR, "Adgangskoden skal fylde 8–72 bytes.\n");
    exit(1);
}

$created = query("INSERT OR IGNORE INTO users (name,email,password,role) VALUES (?,?,?,'admin')",
    [$name,$email,password_hash($password, PASSWORD_DEFAULT)])->rowCount();
if (!$created) {
    fwrite(STDERR, "Der findes allerede en konto med den e-mailadresse.\n");
    exit(1);
}
fwrite(STDOUT, "Platformadministratorens konto er oprettet.\n");
