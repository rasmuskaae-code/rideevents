<?php
$usage = billingUsage($user['organizer_id']);
$annualActive = $usage['annual'] > time();
$session = $_GET['session_id'] ?? '';
$returned = is_string($session) && preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/D', $session)
    ? row('SELECT paid_at,refunded_at FROM billing_orders WHERE session_id=? AND organizer_id=? AND live_mode=?',
        [$session,$user['organizer_id'],stripeLiveMode()])
    : null;
?>
<div class="page-heading">
    <div><h1>Betaling</h1><p><?= e($user['organizer_name']) ?></p></div>
    <?php if ($usage['free'] || $usage['credits'] || $annualActive): ?><a class="button primary" href="?page=admin&amp;event=0">Opret stævne</a><?php endif; ?>
</div>
<?php if ($returned): ?>
    <?php if ($returned['paid_at'] && !$returned['refunded_at']): ?>
        <div class="flash" role="status"><?= icon('check') ?> Betalingen er registreret.</div>
    <?php elseif (!$returned['refunded_at']): ?>
        <div class="flash" role="status"><?= icon('info') ?> Vi afventer bekræftelse fra Stripe. Opdatér siden om lidt.</div>
    <?php endif; ?>
<?php endif; ?>
<?php metricStrip([
    ['flag', 'Gratis stævner tilbage', $usage['free']],
    ['ticket', 'Ekstra stævner tilbage', $usage['credits']],
], 'Dine stævnepladser'); ?>
<?php if ($annualActive): ?>
    <section class="panel admin-section"><h2>Årsadgang er aktiv</h2><p>Du kan oprette ubegrænset antal stævner frem til <?= e(date('d.m.Y', $usage['annual'])) ?>.</p></section>
<?php else: ?>
    <div class="billing-options">
        <?php foreach (['event'=>['Ét ekstra stævne', 'Købet giver plads til ét nyt stævne.'], 'annual'=>['Årsadgang', 'Ubegrænset antal nye stævner i 12 måneder fra betalingen. Fornyes ved et nyt køb.']] as $plan => [$title,$description]): ?>
            <section class="panel admin-section">
                <h2><?= e($title) ?></h2><p><?= e($description) ?></p>
                <?php if (stripeReady()): ?>
                    <form method="post" action="?page=billing">
                        <?php csrf('buy'); ?>
                        <input type="hidden" name="plan" value="<?= e($plan) ?>">
                        <button class="button <?= $plan === 'annual' ? 'primary' : 'secondary' ?>" type="submit">Se pris og betal i Stripe</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <?php if (!stripeReady()): ?><p class="billing-note">Onlinebetaling er endnu ikke aktiveret.</p><?php endif; ?>
<?php endif; ?>
<p class="billing-note">De første tre stævner er gratis pr. arrangør. Et stævne tæller ved oprettelse, også hvis det senere slettes.</p>
