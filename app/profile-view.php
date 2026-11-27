<?php $push = browserPushSettings(); ?>
<div class="page-heading"><div><h1>Min profil</h1><p>Administrér dine kontaktoplysninger og notifikationer.</p></div></div>
<div class="profile-grid">
    <section class="panel admin-section" aria-labelledby="profile-details">
        <h2 id="profile-details">Kontaktoplysninger</h2>
        <form method="post" action="?page=profile" class="form-stack">
            <?php csrf('profile-details'); ?>
            <label>Navn<input name="name" value="<?= e($user['name']) ?>" maxlength="120" autocomplete="name" required></label>
            <label>E-mail<input type="email" name="email" value="<?= e($user['email']) ?>" maxlength="254" autocomplete="email" required></label>
            <label>Telefon<input type="tel" name="phone" value="<?= e($user['phone']) ?>" maxlength="32" autocomplete="tel"></label>
            <label>Nuværende kodeord ved ny e-mail<input type="password" name="current_password" maxlength="72" autocomplete="current-password"></label>
            <div><button class="button primary" type="submit">Gem profil</button></div>
        </form>
    </section>
    <section class="panel admin-section" aria-labelledby="profile-notifications">
        <h2 id="profile-notifications">Notifikationer</h2>
        <form method="post" action="?page=profile" class="form-stack">
            <?php csrf('profile-mail'); ?>
            <label class="checkbox"><input type="checkbox" name="email_notifications" value="1" <?= $user['email_notifications'] ? 'checked' : '' ?>> Send notifikationer på e-mail</label>
            <p class="form-hint">Dit valg gælder beskeder om stævner og vagter.</p>
            <div><button class="button secondary" type="submit">Gem mailvalg</button></div>
        </form>
        <div class="browser-push">
            <h3>Browsernotifikationer</h3>
            <?php if ($push): ?>
                <div data-browser-push data-public-key="<?= e($push['public']) ?>" data-csrf="<?= e($_SESSION['csrf']) ?>">
                    <p role="status" data-push-status>Browsernotifikationer er knyttet til denne browser og enhed.</p>
                    <button class="button secondary" type="button" data-push-toggle hidden>Aktivér på denne enhed</button>
                    <noscript><p>Aktivér JavaScript for at bruge browsernotifikationer.</p></noscript>
                </div>
            <?php else: ?>
                <p>Browsernotifikationer er endnu ikke sat op.</p>
            <?php endif; ?>
        </div>
    </section>
    <section class="panel admin-section" aria-labelledby="profile-password">
        <h2 id="profile-password">Skift kodeord</h2>
        <form method="post" action="?page=profile" class="form-stack">
            <?php csrf('profile-password'); ?>
            <label>Nuværende kodeord<input type="password" name="current_password" maxlength="72" autocomplete="current-password" required></label>
            <label>Nyt kodeord<input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
            <label>Gentag nyt kodeord<input type="password" name="password_confirm" minlength="8" maxlength="72" autocomplete="new-password" required></label>
            <div><button class="button primary" type="submit">Gem nyt kodeord</button></div>
        </form>
        <p class="profile-reset"><a class="text-link" href="?page=forgot">Glemt kodeord?</a></p>
    </section>
</div>
