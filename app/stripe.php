<?php
declare(strict_types=1);

function stripeSettings(): array
{
    $settings = appConfig()['stripe'] ?? [];
    return is_array($settings) ? $settings : [];
}

function stripeLiveMode(): int
{
    $key = (string) (stripeSettings()['secret_key'] ?? '');
    return (int) (!preg_match('/^(?:sk|rk)_test_/', $key));
}

function stripeReady(): bool
{
    $settings = stripeSettings();
    $url = parse_url((string) ($settings['public_url'] ?? ''));
    return function_exists('curl_init')
        && preg_match('/^(?:sk|rk)_(?:test|live)_[A-Za-z0-9_]+$/D', (string) ($settings['secret_key'] ?? ''))
        && str_starts_with((string) ($settings['webhook_secret'] ?? ''), 'whsec_')
        && preg_match('/^price_[A-Za-z0-9]+$/D', (string) ($settings['event_price'] ?? ''))
        && preg_match('/^price_[A-Za-z0-9]+$/D', (string) ($settings['annual_price'] ?? ''))
        && $settings['event_price'] !== $settings['annual_price']
        && filter_var($settings['public_url'] ?? '', FILTER_VALIDATE_URL)
        && ($url['scheme'] ?? '') === 'https'
        && !isset($url['query']) && !isset($url['fragment']) && !isset($url['user']);
}

function stripeRequest(string $path, array $parameters, ?string $idempotencyKey = null): array
{
    $key = (string) (stripeSettings()['secret_key'] ?? '');
    if (!function_exists('curl_init') || !preg_match('/^(?:sk|rk)_(?:test|live)_[A-Za-z0-9_]+$/D', $key)) {
        throw new RuntimeException('Stripe kræver cURL og en gyldig API-nøgle i config.php.');
    }

    $post = $idempotencyKey !== null;
    $encoded = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    $url = 'https://api.stripe.com/v1/' . $path . ($post || $encoded === '' ? '' : '?' . $encoded);
    $curl = curl_init($url);
    $headers = ['Authorization: Bearer ' . $key, 'Stripe-Version: 2024-06-20'];
    if ($post) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
    }
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $result = is_string($body) ? json_decode($body, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($result)) {
        $code = (string) ($result['error']['code'] ?? 'unavailable');
        throw new RuntimeException("Stripe svarede med HTTP $status ($code).");
    }
    return $result;
}

function verifyStripeEvent(string $body, string $header, string $secret, int $now): ?array
{
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $pair) {
        [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
        if ($name === 't' && preg_match('/^[0-9]{1,12}$/D', $value)) {
            $timestamp = (int) $value;
        } elseif ($name === 'v1') {
            $signatures[] = $value;
        }
    }
    if ($timestamp === null || abs($timestamp - $now) > 300) {
        return null;
    }
    $expected = hash_hmac('sha256', "$timestamp.$body", $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            $event = json_decode($body, true);
            return is_array($event) ? $event : null;
        }
    }
    return null;
}
