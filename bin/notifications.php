<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

reminders();

$from = getenv('STAEVNE_MAIL_FROM');
if ($from === false || $from === '') {
    exit;
}
if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "STAEVNE_MAIL_FROM skal være en gyldig e-mailadresse.\n");
    exit(1);
}

$path = getenv('STAEVNE_DB') ?: dirname(__DIR__) . '/var/staevnevagt.sqlite';
$lock = fopen($path . '.mail.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}

$notices = query("SELECT n.id,n.title,n.body,u.email FROM notices n JOIN users u ON u.id=n.user_id
    WHERE n.mail_sent_at IS NULL AND (
        n.reminder_key IS NULL OR EXISTS (
            SELECT 1 FROM signups s JOIN shifts v ON v.id=s.shift_id
            WHERE s.user_id=n.user_id AND n.reminder_key=s.id || ':' || v.starts_at AND v.starts_at > ?
        )
    ) ORDER BY n.created_at,n.id", [time()])->fetchAll();
$headers = ['From' => $from, 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8'];
$url = rtrim(getenv('STAEVNE_URL') ?: '', '/');
$failed = false;
foreach ($notices as $notice) {
    $subject = '=?UTF-8?B?' . base64_encode('Stævnevagt · ' . $notice['title']) . '?=';
    $body = $notice['body'] . ($url === '' ? '' : "\n\n" . $url . '/?page=notices');
    if (mail($notice['email'], $subject, wordwrap($body, 72), $headers)) {
        query('UPDATE notices SET mail_sent_at=? WHERE id=?', [time(), $notice['id']]);
    } else {
        fwrite(STDERR, "Mail til notifikation {$notice['id']} kunne ikke afleveres.\n");
        $failed = true;
    }
}
fclose($lock);
exit($failed ? 1 : 0);
