<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$directory = dirname(__DIR__) . '/var';
if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
    fwrite(STDERR, "Kunne ikke oprette var-mappen.\n");
    exit(1);
}

$key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC, 'curve_name'=>'prime256v1']);
if (!$key || !openssl_pkey_export($key, $pem)) {
    fwrite(STDERR, "Kunne ikke oprette en VAPID-nøgle.\n");
    exit(1);
}
$oldMask = umask(0077);
$file = @fopen($directory . '/browser-push.pem', 'x');
umask($oldMask);
if (!$file) {
    fwrite(STDERR, "Kunne ikke oprette var/browser-push.pem. En eksisterende nøgle bevares.\n");
    exit(1);
}
if (fwrite($file, $pem) !== strlen($pem)) {
    fclose($file);
    unlink($directory . '/browser-push.pem');
    fwrite(STDERR, "Kunne ikke gemme hele VAPID-nøglen.\n");
    exit(1);
}
fclose($file);
fwrite(STDOUT, "VAPID-nøglen er gemt i var/browser-push.pem.\n");
