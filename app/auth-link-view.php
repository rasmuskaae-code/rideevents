<?php
$request = in_array($page, ['forgot','quick-login'], true);
$reset = in_array($page, ['forgot','reset-password'], true);
$requestPage = $reset ? 'forgot' : 'quick-login';
$heading = match ($page) {
    'forgot' => 'Glemt kodeord',
    'reset-password' => 'Vælg et nyt kodeord',
    default => 'Hurtigt login',
};
?>
<section class="panel auth-card">
    <span class="tile"><?= icon('user', 22) ?></span>
    <h1><?= $heading ?></h1>
    <?php if ($request): ?>
        <p><?= $reset ? 'Vi sender et link, som du kan bruge til at vælge et nyt kodeord.' : 'Indtast din e-mailadresse, så sender vi et engangslink til login.' ?></p>
        <?php if (authLinkMailSettings()): ?>
            <form method="post" action="?page=<?= $page ?>&amp;event=<?= $queryId ?>" class="form-stack">
                <?php csrf($page); ?>
                <label>E-mail<input type="email" name="email" autocomplete="email" maxlength="254" required></label>
                <button class="button primary" type="submit"><?= $reset ? 'Send link til nyt kodeord' : 'Send loginlink' ?></button>
            </form>
        <?php else: ?>
            <p class="auth-unavailable">Login med e-mail er endnu ikke aktiveret.</p>
        <?php endif; ?>
    <?php else: ?>
        <?php if (!$reset): ?><p>Bekræft login med det link, du fik i din mail.</p><?php endif; ?>
        <form method="post" action="?page=<?= $page ?>" class="form-stack" data-auth-link>
            <?php csrf($page); ?>
            <input type="hidden" name="token" data-link-token>
            <noscript><label>Engangskode<input name="token" maxlength="64" autocomplete="off" required></label><p>Kopiér koden efter # i linket fra din mail.</p></noscript>
            <p class="auth-unavailable" data-link-error hidden>Åbn det fulde link fra din mail, eller bed om et nyt link.</p>
            <?php if ($reset): ?>
                <label>Nyt kodeord<input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="new-password-help" required></label>
                <p class="form-hint" id="new-password-help">Brug mindst 8 tegn.</p>
                <label>Gentag kodeord<input type="password" name="password_confirm" minlength="8" maxlength="72" autocomplete="new-password" required></label>
            <?php endif; ?>
            <button class="button primary" type="submit"><?= $reset ? 'Gem nyt kodeord' : 'Log ind' ?></button>
        </form>
    <?php endif; ?>
    <div class="auth-switch">
        <?php if (!$request): ?><a class="text-link" href="?page=<?= $requestPage ?>">Bed om et nyt link</a> · <?php endif; ?>
        <a class="text-link" href="?page=login&amp;event=<?= $queryId ?>">Tilbage til login</a>
    </div>
</section>
