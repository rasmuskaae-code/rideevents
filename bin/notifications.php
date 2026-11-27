<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/browser-push.php';

reminders();

$failed = false;
try {
    sendBrowserPush();
} catch (Throwable) {
    fwrite(STDERR, "Browsernotifikationer kunne ikke behandles.\n");
    $failed = true;
}

$from = getenv('STAEVNE_MAIL_FROM');
if ($from === false || $from === '') {
    exit($failed ? 1 : 0);
}
if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "STAEVNE_MAIL_FROM skal være en gyldig e-mailadresse.\n");
    exit(1);
}

$mysql = usingMysql();
$lock = $mysql ? mysqlLock(db(), 'mail', 0) : fopen(databaseConfig()['path'] . '.mail.lock', 'c');
if (!$lock || (!$mysql && !flock($lock, LOCK_EX | LOCK_NB))) {
    exit($failed ? 1 : 0);
}

try {
    $reminderKey = sqlConcat('s.id', "':'", 'v.starts_at');
    $notices = query("SELECT n.id,n.title,n.body,u.email FROM notices n JOIN users u ON u.id=n.user_id
        LEFT JOIN user_preferences p ON p.user_id=u.id
        WHERE n.mail_sent_at IS NULL AND COALESCE(p.email_notifications,1)=1
          AND n.id>COALESCE(p.email_notice_after_id,0) AND (
            n.reminder_key IS NULL OR EXISTS (
                SELECT 1 FROM signups s JOIN shifts v ON v.id=s.shift_id
                WHERE s.user_id=n.user_id AND n.reminder_key=$reminderKey AND v.starts_at > ?
            )
        ) ORDER BY n.created_at,n.id", [time()])->fetchAll();
    $headers = ['From' => $from, 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8'];
    $url = rtrim(getenv('STAEVNE_URL') ?: '', '/');
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
} finally {
    if ($mysql) {
        releaseMysqlLock(db(), $lock);
    } else {
        fclose($lock);
    }
}
exit($failed ? 1 : 0);
