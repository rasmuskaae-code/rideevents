<?php
declare(strict_types=1);

function startUserSession(int $id, string $passwordHash): void
{
    session_regenerate_id(true);
    $_SESSION = ['user'=>$id, 'database'=>databaseIdentity(), 'credential'=>hash('sha256', $passwordHash)];
}

function loginDestination(array $account, int $event): string
{
    if (isPlatformAdmin($account)) {
        return '?page=platform';
    }
    return canManageEvents($account) && !$event ? '?page=admin&event=0' : '?event=' . $event;
}

function authLinkMailSettings(): ?array
{
    $config = appConfig();
    $mail = $config['auth_mail'] ?? [];
    $from = $mail['from'] ?? (getenv('STAEVNE_MAIL_FROM') ?: '');
    $base = $mail['public_url'] ?? (getenv('STAEVNE_URL') ?: ($config['stripe']['public_url'] ?? ''));
    $url = is_string($base) ? parse_url($base) : false;

    if (!function_exists('mail') || !is_string($from) || !filter_var($from, FILTER_VALIDATE_EMAIL)
        || !is_string($base) || !filter_var($base, FILTER_VALIDATE_URL) || ($url['scheme'] ?? '') !== 'https'
        || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) {
        return null;
    }
    return ['from'=>$from, 'base'=>rtrim($base, '/') . '/'];
}

function countLoginAttempt(string $key, int $window): int
{
    $insert = usingMysql()
        ? 'INSERT INTO login_attempts (`key`,`window`,`count`) VALUES (?,?,0) ON DUPLICATE KEY UPDATE `count`=`count`'
        : 'INSERT OR IGNORE INTO login_attempts (`key`,`window`,`count`) VALUES (?,?,0)';
    query($insert, [$key,$window]);
    query('UPDATE login_attempts SET `count`=CASE WHEN `window`=? THEN `count`+1 ELSE 1 END, `window`=? WHERE `key`=?',
        [$window,$window,$key]);
    return (int) query('SELECT `count` FROM login_attempts WHERE `key`=?', [$key])->fetchColumn();
}

function requestAuthLink(string $purpose, int $event): void
{
    $mail = authLinkMailSettings();
    if (!$mail) {
        throw new InputError('Login med e-mail er endnu ikke aktiveret.');
    }

    $email = strtolower(field('email', 'E-mail', 254));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InputError('Indtast en gyldig e-mailadresse.');
    }

    $now = time();
    $source = hash('sha256', 'auth-link-ip|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $recipient = hash('sha256', 'auth-link-email|' . $email);
    $token = bin2hex(random_bytes(32));
    $digest = hash('sha256', $token);
    $minutes = $purpose === 'reset' ? 30 : 15;
    $send = atomic(function () use ($source, $recipient, $now, $email, $purpose, $event, $minutes, $digest): bool {
        $window = intdiv($now, 600);
        if (countLoginAttempt($source, $window) > 20 || countLoginAttempt($recipient, $window) > 4) {
            return false;
        }
        $user = lockedRow('SELECT id FROM users WHERE email=?', [$email]);
        if (!$user) {
            return false;
        }
        query('INSERT INTO auth_links (token_hash,user_id,purpose,event_id,expires_at) VALUES (?,?,?,?,?)',
            [$digest,$user['id'],$purpose,max($event,0),$now + 60 * $minutes]);
        return true;
    });

    query('DELETE FROM login_attempts WHERE `window` < ?', [intdiv($now, 600) - 24]);
    query('DELETE FROM auth_links WHERE expires_at<=?', [$now]);
    if (!$send) {
        return;
    }

    $page = $purpose === 'reset' ? 'reset-password' : 'login-confirm';
    $link = $mail['base'] . '?page=' . $page . '#' . $token;
    $title = $purpose === 'reset' ? 'Vælg et nyt kodeord' : 'Log ind med engangslink';
    $subject = '=?UTF-8?B?' . base64_encode('Stævnevagt · ' . $title) . '?=';
    $message = "$title på Stævnevagt:\n\n$link\n\nLinket gælder i $minutes minutter og kan bruges én gang.\n"
        . "Hvis du ikke har bedt om linket, kan du se bort fra mailen.";
    $headers = [
        'From'=>$mail['from'],
        'MIME-Version'=>'1.0',
        'Content-Type'=>'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding'=>'base64',
    ];
    if (!mail($email, $subject, chunk_split(base64_encode($message)), $headers)) {
        query('DELETE FROM auth_links WHERE token_hash=?', [$digest]);
        error_log('Stævnevagt: Kunne ikke aflevere en e-mail med et loginlink.');
    }
}

function validAuthToken(string $token): bool
{
    return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
}

function redeemAuthLink(string $purpose, string $token, ?string $newPassword = null): ?array
{
    if (!validAuthToken($token)) {
        return null;
    }
    $digest = hash('sha256', $token);
    $target = row('SELECT user_id FROM auth_links WHERE token_hash=? AND purpose=? AND expires_at>?', [$digest,$purpose,time()]);
    if (!$target) {
        return null;
    }

    $newHash = $newPassword === null ? null : password_hash($newPassword, PASSWORD_DEFAULT);
    return atomic(function () use ($purpose, $digest, $target, $newHash): ?array {
        $user = lockedRow('SELECT id,password FROM users WHERE id=?', [$target['user_id']]);
        $link = lockedRow('SELECT event_id,expires_at FROM auth_links WHERE token_hash=? AND user_id=? AND purpose=?',
            [$digest,$target['user_id'],$purpose]);
        if (!$user || !$link || $link['expires_at'] <= time()) {
            return null;
        }

        if ($newHash !== null) {
            query('UPDATE users SET password=? WHERE id=?', [$newHash,$user['id']]);
            query('DELETE FROM auth_links WHERE user_id=?', [$user['id']]);
        } else {
            query('DELETE FROM auth_links WHERE token_hash=?', [$digest]);
        }
        return ['id'=>$user['id'], 'password'=>$user['password'], 'event'=>$link['event_id']];
    });
}
