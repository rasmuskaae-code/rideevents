<?php
declare(strict_types=1);

function base64Url(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function browserPushSettings(): ?array
{
    static $settings = false;
    if ($settings !== false) {
        return $settings;
    }

    $config = appConfig()['browser_push'] ?? [];
    $subject = $config['subject'] ?? '';
    $file = $config['private_key_file'] ?? '';
    if (!function_exists('curl_init') || !function_exists('openssl_pkey_get_private')
        || !is_string($subject) || !str_starts_with($subject, 'mailto:') || !filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL)
        || !is_string($file) || !is_readable($file)) {
        return $settings = null;
    }
    $pem = @file_get_contents($file);
    $key = $pem ? openssl_pkey_get_private($pem) : false;
    $details = $key ? openssl_pkey_get_details($key) : false;
    $ec = $details['ec'] ?? [];
    if (!in_array($ec['curve_name'] ?? '', ['prime256v1','secp256r1'], true) || !isset($ec['x'], $ec['y'])) {
        return $settings = null;
    }
    $public = base64Url("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
    return $settings = ['subject'=>$subject, 'public'=>$public, 'key'=>$key];
}

function pushAudience(string $endpoint): ?string
{
    $url = strlen($endpoint) <= 2048 && filter_var($endpoint, FILTER_VALIDATE_URL) ? parse_url($endpoint) : false;
    $host = strtolower($url['host'] ?? '');
    if (($url['scheme'] ?? '') !== 'https' || ($url['port'] ?? 443) !== 443 || isset($url['user'])
        || isset($url['pass']) || isset($url['fragment']) || empty($url['path'])) {
        return null;
    }
    if (!in_array($host, ['fcm.googleapis.com','android.googleapis.com','updates.push.services.mozilla.com'], true)
        && !str_ends_with($host, '.push.apple.com') && !str_ends_with($host, '.notify.windows.com')
        && !str_ends_with($host, '.wns.windows.com')) {
        return null;
    }
    return 'https://' . $host;
}

function respondToPushAction(int $user, string $action, string $endpoint): never
{
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || pushAudience($endpoint) === null) {
        http_response_code(400);
        echo json_encode(['message'=>'Browseren leverede en adresse, som Stævnevagt ikke understøtter.']);
        exit;
    }
    $hash = hash('sha256', $endpoint);
    $active = atomic(function () use ($user, $action, $hash, $endpoint): ?bool {
        $entry = lockedRow('SELECT user_id FROM push_subscriptions WHERE endpoint_hash=?', [$hash]);
        $own = $entry && $entry['user_id'] === $user;
        if ($action === 'push-status') {
            return $own;
        }
        if ($action === 'push-disable') {
            if ($own) {
                query('DELETE FROM push_subscriptions WHERE endpoint_hash=? AND user_id=?', [$hash,$user]);
            }
            return false;
        }
        if (!browserPushSettings() || ($entry && !$own)) {
            return null;
        }
        if (!$entry) {
            $cursor = (int) query('SELECT MAX(id) FROM notices WHERE user_id=?', [$user])->fetchColumn();
            return insertUnique('INSERT INTO push_subscriptions (endpoint_hash,user_id,endpoint,last_notice_id) VALUES (?,?,?,?)',
                [$hash,$user,$endpoint,$cursor]) ? true : null;
        }
        return true;
    });
    if ($active === null) {
        http_response_code(409);
        echo json_encode(['message'=>'Browsernotifikationer kunne ikke aktiveres på denne enhed.']);
        exit;
    }
    echo json_encode(['active'=>$active]);
    exit;
}

function vapidAuthorization(string $audience, array $settings, int $now): string
{
    $encoded = base64Url('{"typ":"JWT","alg":"ES256"}') . '.' . base64Url(json_encode([
        'aud'=>$audience, 'exp'=>$now + 3600, 'sub'=>$settings['subject'],
    ], JSON_THROW_ON_ERROR));
    if (!openssl_sign($encoded, $signature, $settings['key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID-underskriften kunne ikke oprettes.');
    }

    // OpenSSL emits DER integers; ES256 expects their 32-byte values concatenated.
    $firstSize = ord($signature[3]);
    $secondOffset = 4 + $firstSize;
    $secondSize = ord($signature[$secondOffset + 1]);
    $r = substr($signature, 4, $firstSize);
    $s = substr($signature, $secondOffset + 2, $secondSize);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return 'vapid t=' . $encoded . '.' . base64Url($raw) . ', k=' . $settings['public'];
}

function sendBrowserPush(): void
{
    $settings = browserPushSettings();
    if (!$settings) {
        return;
    }
    $mysql = usingMysql();
    $lock = $mysql ? mysqlLock(db(), 'push', 0) : fopen(databaseConfig()['path'] . '.push.lock', 'c');
    if (!$lock || (!$mysql && !flock($lock, LOCK_EX | LOCK_NB))) {
        return;
    }
    try {
        $now = time();
        $reminderKey = sqlConcat('s.id', "':'", 'v.starts_at');
        $pending = query("SELECT p.endpoint_hash,p.endpoint,p.user_id,p.last_notice_id,
            (SELECT MAX(n.id) FROM notices n WHERE n.user_id=p.user_id AND n.id>p.last_notice_id AND n.read_at IS NULL AND (
                n.reminder_key IS NULL OR EXISTS (SELECT 1 FROM signups s JOIN shifts v ON v.id=s.shift_id
                    WHERE s.user_id=n.user_id AND n.reminder_key=$reminderKey AND v.starts_at>?)
            )) AS notice_id
            FROM push_subscriptions p WHERE p.next_attempt_at<=?", [$now,$now])->fetchAll();

        $authorizations = [];
        foreach ($pending as $entry) {
            if (!$entry['notice_id']) {
                continue;
            }
            $endpoint = $entry['endpoint'];
            $audience = pushAudience($endpoint);
            if ($audience === null) {
                query('DELETE FROM push_subscriptions WHERE endpoint_hash=? AND user_id=?', [$entry['endpoint_hash'],$entry['user_id']]);
                continue;
            }

            $auth = $authorizations[$audience] ??= vapidAuthorization($audience, $settings, $now);
            $curl = curl_init($endpoint);
            curl_setopt_array($curl, [
                CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>'', CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>7,
                CURLOPT_HTTPHEADER=>['Authorization: ' . $auth, 'TTL: 600', 'Content-Length: 0'],
            ]);
            curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status >= 200 && $status < 300) {
                query('UPDATE push_subscriptions SET last_notice_id=? WHERE endpoint_hash=? AND user_id=? AND last_notice_id=?',
                    [$entry['notice_id'],$entry['endpoint_hash'],$entry['user_id'],$entry['last_notice_id']]);
            } elseif (in_array($status, [404,410], true)) {
                query('DELETE FROM push_subscriptions WHERE endpoint_hash=? AND user_id=?', [$entry['endpoint_hash'],$entry['user_id']]);
            } else {
                query('UPDATE push_subscriptions SET next_attempt_at=? WHERE endpoint_hash=? AND user_id=?', [$now+300,$entry['endpoint_hash'],$entry['user_id']]);
                error_log("Stævnevagt: Browserens push-tjeneste svarede med HTTP $status.");
            }
        }
    } finally {
        if ($mysql) {
            releaseMysqlLock(db(), $lock);
        } else {
            fclose($lock);
        }
    }
}
