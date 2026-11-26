<?php
declare(strict_types=1);

final class BillingLimit extends RuntimeException {}

const FREE_EVENTS = 3;

function annualAccessUntil(int $organizer, int $liveMode): int
{
    return (int) query("SELECT MAX(access_until) FROM billing_orders
        WHERE organizer_id=? AND live_mode=? AND plan='annual' AND paid_at IS NOT NULL AND refunded_at IS NULL",
        [$organizer,$liveMode])->fetchColumn();
}

function billingUsage(int $organizer): array
{
    $used = (int) query('SELECT created_count FROM organizer_event_usage WHERE organizer_id=?', [$organizer])->fetchColumn();
    $mode = stripeLiveMode();
    $credits = (int) query("SELECT COUNT(*) FROM billing_orders
        WHERE organizer_id=? AND live_mode=? AND plan='event' AND paid_at IS NOT NULL AND refunded_at IS NULL AND consumed_at IS NULL",
        [$organizer,$mode])->fetchColumn();
    return ['free'=>max(0, FREE_EVENTS - $used), 'credits'=>$credits, 'annual'=>annualAccessUntil($organizer, $mode)];
}

function consumeEventAllowance(int $organizer): void
{
    $owner = lockedRow('SELECT owner_id FROM organizers WHERE id=?', [$organizer]);
    if ($owner['owner_id'] === null) {
        return;
    }

    $usage = row('SELECT created_count FROM organizer_event_usage WHERE organizer_id=?', [$organizer]);
    $mode = stripeLiveMode();
    if (($usage['created_count'] ?? 0) >= FREE_EVENTS && annualAccessUntil($organizer, $mode) <= time()) {
        $credit = row("SELECT id FROM billing_orders WHERE organizer_id=? AND live_mode=? AND plan='event'
            AND paid_at IS NOT NULL AND refunded_at IS NULL AND consumed_at IS NULL ORDER BY paid_at,id LIMIT 1", [$organizer,$mode]);
        if (!$credit) {
            throw new BillingLimit('Arrangøren har brugt de tre gratis stævner. Køb et ekstra stævne eller årsadgang.');
        }
        query('UPDATE billing_orders SET consumed_at=? WHERE id=?', [time(),$credit['id']]);
    }
    if ($usage) {
        query('UPDATE organizer_event_usage SET created_count=created_count+1 WHERE organizer_id=?', [$organizer]);
    } else {
        query('INSERT INTO organizer_event_usage (organizer_id,created_count) VALUES (?,1)', [$organizer]);
    }
}

function startCheckout(array $user, string $plan): never
{
    if (!canManageEvents($user) || empty($user['organizer_id'])) {
        throw new AccessDenied();
    }
    if (!in_array($plan, ['event','annual'], true) || !stripeReady()) {
        throw new InputError('Betaling er ikke tilgængelig lige nu.');
    }
    $settings = stripeSettings();
    $price = $settings[$plan . '_price'];
    $mode = stripeLiveMode();
    $organizer = $user['organizer_id'];
    $order = atomic(function () use ($user, $organizer, $price, $mode, $plan): array {
        if (!lockedRow('SELECT id FROM organizers WHERE id=? AND owner_id=? AND active=1', [$organizer,$user['id']])) {
            throw new AccessDenied();
        }
        $now = time();
        if (annualAccessUntil($organizer, $mode) > $now) {
            throw new InputError('Din årsadgang er aktiv.');
        }
        $pending = row("SELECT * FROM billing_orders WHERE organizer_id=? AND live_mode=? AND plan=? AND price_id=?
            AND closed_at IS NULL AND paid_at IS NULL AND refunded_at IS NULL AND expires_at>?
            AND (session_id IS NOT NULL OR expires_at>?) ORDER BY created_at DESC LIMIT 1",
            [$organizer,$mode,$plan,$price,$now,$now + 1800]);
        if ($pending) {
            return $pending;
        }
        $order = ['id'=>bin2hex(random_bytes(16)), 'expires_at'=>$now + 7200, 'checkout_url'=>null];
        query('INSERT INTO billing_orders (id,organizer_id,plan,live_mode,price_id,expires_at,created_at) VALUES (?,?,?,?,?,?,?)',
            [$order['id'],$organizer,$plan,$mode,$price,$order['expires_at'],$now]);
        return $order;
    });
    if ($order['checkout_url']) {
        go($order['checkout_url']);
    }

    $id = $order['id'];
    $returnUrl = rtrim($settings['public_url'], '/') . '/?page=billing';
    try {
        $session = stripeRequest('checkout/sessions', [
            'mode'=>'payment',
            'payment_method_types'=>['card'],
            'line_items'=>[['price'=>$price, 'quantity'=>1]],
            'client_reference_id'=>$id,
            'metadata'=>['staevnevagt_order'=>$id],
            'payment_intent_data'=>['metadata'=>['staevnevagt_order'=>$id]],
            'expires_at'=>$order['expires_at'],
            'success_url'=>$returnUrl . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'=>$returnUrl . '&cancelled=1',
        ], 'staevnevagt-checkout-' . $id);

        $target = $session['url'] ?? '';
        $url = parse_url($target);
        if (($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== 'checkout.stripe.com'
            || !preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/D', $session['id'] ?? '')
            || ($session['livemode'] ?? null) !== (bool) $mode) {
            throw new RuntimeException('Stripe returnerede en uventet Checkout-session.');
        }
        query('UPDATE billing_orders SET session_id=?,checkout_url=? WHERE id=? AND session_id IS NULL', [$session['id'],$target,$id]);
    } catch (RuntimeException $error) {
        error_log($error->getMessage());
        throw new InputError('Betalingen kunne ikke åbnes. Prøv igen om lidt.');
    }
    go($target);
}

function applyStripeEvent(array $event): void
{
    $type = $event['type'] ?? '';
    if (!in_array($type, ['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired','charge.refunded'], true)) {
        return;
    }
    $object = $event['data']['object'] ?? [];
    $id = $object['metadata']['staevnevagt_order'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
        return;
    }
    $owner = row('SELECT organizer_id FROM billing_orders WHERE id=?', [$id]);
    if (!$owner) {
        return;
    }

    atomic(function () use ($event, $type, $object, $id, $owner): void {
        lockedRow('SELECT id FROM organizers WHERE id=?', [$owner['organizer_id']]);
        $order = row('SELECT * FROM billing_orders WHERE id=?', [$id]);
        if (($event['livemode'] ?? null) !== (bool) $order['live_mode']) {
            return;
        }

        $intent = $object['payment_intent'] ?? '';
        if ($type === 'charge.refunded') {
            if (($object['refunded'] ?? false) === true && is_string($intent) && str_starts_with($intent, 'pi_')
                && ($order['payment_intent'] === null || $order['payment_intent'] === $intent)) {
                query('UPDATE billing_orders SET refunded_at=COALESCE(refunded_at,?),payment_intent=? WHERE id=?', [time(),$intent,$id]);
            }
            return;
        }

        if (($object['id'] ?? null) !== $order['session_id'] || $order['session_id'] === null) {
            return;
        }
        if ($type === 'checkout.session.expired') {
            query('UPDATE billing_orders SET closed_at=? WHERE id=? AND paid_at IS NULL', [time(),$id]);
            return;
        }
        if (($object['payment_status'] ?? '') !== 'paid' || ($object['mode'] ?? '') !== 'payment'
            || ($object['amount_total'] ?? 0) <= 0 || !is_string($intent) || !str_starts_with($intent, 'pi_')
            || $order['paid_at'] !== null || $order['refunded_at'] !== null) {
            return;
        }
        $now = time();
        $paidAt = max($order['created_at'], min($now, (int) ($event['created'] ?? $now)));
        $until = null;
        if ($order['plan'] === 'annual') {
            $start = max($paidAt, annualAccessUntil($order['organizer_id'], $order['live_mode']));
            $until = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone('Europe/Copenhagen'))->modify('+1 year')->getTimestamp();
        }
        query('UPDATE billing_orders SET paid_at=?,payment_intent=?,access_until=?,checkout_url=NULL WHERE id=?',
            [$paidAt,$intent,$until,$id]);
    });
}

function handleStripeWebhook(): never
{
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit;
    }
    $secret = (string) (stripeSettings()['webhook_secret'] ?? '');
    if (!str_starts_with($secret, 'whsec_')) {
        http_response_code(503);
        exit;
    }
    $body = file_get_contents('php://input', false, null, 0, 1048576);
    $event = verifyStripeEvent($body ?: '', $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $secret, time());
    if (!$event) {
        http_response_code(400);
        exit;
    }

    applyStripeEvent($event);
    http_response_code(204);
    exit;
}
