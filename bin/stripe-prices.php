<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/stripe.php';

$mode = $argv[1] ?? '';
if ($argc !== 2 || !in_array($mode, ['test','live'], true) || (int) ($mode === 'live') !== stripeLiveMode()) {
    fwrite(STDERR, "Brug: php bin/stripe-prices.php test|live. Argument og Stripe-nøgle skal være i samme miljø.\n");
    exit(1);
}

try {
    $plans = [
        'event' => ['staevnevagt_event_dkk_79', 'Stævnevagt · Ét ekstra stævne', 7900],
        'annual' => ['staevnevagt_annual_dkk_499', 'Stævnevagt · Årsadgang (12 måneder)', 49900],
    ];
    foreach ($plans as $plan => [$lookup,$title,$amount]) {
        $existing = stripeRequest('prices', ['lookup_keys'=>[$lookup], 'limit'=>1]);
        $price = $existing['data'][0] ?? stripeRequest('prices', [
            'currency'=>'dkk',
            'unit_amount'=>$amount,
            'lookup_key'=>$lookup,
            'product_data'=>['name'=>$title],
        ], 'staevnevagt-price-' . $lookup);
        if (($price['active'] ?? null) !== true || ($price['type'] ?? null) !== 'one_time'
            || ($price['unit_amount'] ?? null) !== $amount || ($price['currency'] ?? null) !== 'dkk'
            || ($price['livemode'] ?? null) !== (bool) stripeLiveMode()
            || !preg_match('/^price_[A-Za-z0-9]+$/D', $price['id'] ?? '')) {
            throw new RuntimeException("Kontrollér den eksisterende Stripe-pris med nøglen $lookup.");
        }
        fwrite(STDOUT, "'{$plan}_price' => '{$price['id']}',\n");
    }
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
