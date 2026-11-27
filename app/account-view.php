<?php if (in_array($page, ['login','register','organizer'], true)):
    $organizerSignup = $page === 'organizer';
    $register = $page !== 'login';
    $heading = $organizerSignup ? 'Opret arrangør' : ($register ? 'Opret bruger' : 'Log ind');
?>
<section class="panel auth-card">
    <span class="tile"><?= icon($organizerSignup ? 'users' : 'user', 22) ?></span>
    <h1><?= $heading ?></h1>
    <p><?php if ($organizerSignup): ?>Opret din rideklub eller arrangør, så du kan planlægge stævner og tage imod tilmeldinger.
        <?php elseif ($register): ?>Opret din profil, så du kan melde dig som hjælper.
        <?php else: ?>Log ind for at se dine vagter eller administrere dine stævner.<?php endif; ?></p>
    <form method="post" class="form-stack">
        <?php csrf($page); ?>
        <?php if ($organizerSignup): ?><label>Klub- eller arrangørnavn<input name="organizer_name" autocomplete="organization" maxlength="160" required></label><?php endif; ?>
        <?php if (!$user): ?>
            <?php if ($register): ?><label>Dit navn<input name="name" autocomplete="name" maxlength="120" required></label><?php endif; ?>
            <label>E-mail<input type="email" name="email" autocomplete="email" maxlength="254" required></label>
            <label>Adgangskode<input type="password" name="password" autocomplete="<?= $register ? 'new-password' : 'current-password' ?>" <?= $register ? 'minlength="8" aria-describedby="password-help"' : '' ?> maxlength="72" required></label>
            <?php if ($register): ?><p class="form-hint" id="password-help">Brug mindst 8 tegn.</p><?php endif; ?>
        <?php endif; ?>
        <?php if ($page === 'login'): ?>
            <a class="text-link auth-forgot" href="?page=forgot&amp;event=<?= $queryId ?>">Glemt kodeord?</a>
        <?php endif; ?>
        <button class="button primary" type="submit"><?= $heading ?></button>
    </form>
    <?php if ($page === 'login'): ?>
        <div class="auth-alternative"><a class="button secondary" href="?page=quick-login&amp;event=<?= $queryId ?>">Send mig et loginlink</a></div>
    <?php endif; ?>
    <?php if (!$user): ?>
    <div class="auth-switch">
        <?php if ($register): ?>Har du allerede en profil? <a class="text-link" href="?page=login<?= $queryId ? '&amp;event=' . $queryId : '' ?>">Log ind</a>
        <?php else: ?>Har du ingen profil endnu? <a class="text-link" href="?page=register<?= $queryId ? '&amp;event=' . $queryId : '' ?>">Opret hjælperprofil</a><?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<?php elseif (in_array($page, ['forgot','quick-login','login-confirm','reset-password'], true)):
    require __DIR__ . '/auth-link-view.php';
elseif ($page === 'mine'):
    $shifts = query('SELECT v.*,g.name AS group_name,g.id AS group_id,e.name AS event_name,o.name AS organizer_name FROM signups s JOIN shifts v ON v.id=s.shift_id
        JOIN `groups` g ON g.id=v.group_id JOIN events e ON e.id=g.event_id JOIN organizers o ON o.id=e.organizer_id WHERE s.user_id=? ORDER BY v.starts_at DESC,v.id DESC', [$user['id']])->fetchAll();
    $responsibilities = query('SELECT g.name AS group_name,e.name AS event_name,o.name AS organizer_name
        FROM group_leads l JOIN `groups` g ON g.id=l.group_id JOIN events e ON e.id=g.event_id
        JOIN organizers o ON o.id=e.organizer_id WHERE l.user_id=? ORDER BY e.starts_on,g.id', [$user['id']])->fetchAll();
?>
<section class="interior">
    <h1>Mine vagter</h1><p>Kontakt stævnets arrangør, hvis du skal afmeldes en vagt.</p>
    <?php if ($responsibilities): ?>
        <h2>Vagtgrupper, du er ansvarlig for</h2>
        <div class="panel list-panel">
            <?php foreach ($responsibilities as $responsibility): ?>
                <div class="listing"><span class="tile"><?= icon('flag') ?></span><div>
                    <h3><?= e($responsibility['group_name']) ?></h3>
                    <p><?= e($responsibility['event_name']) ?> · <?= e($responsibility['organizer_name']) ?></p>
                </div></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!$shifts): ?><div class="panel empty-panel"><h2>Du har ingen tilmeldte vagter</h2><a class="button primary" href="<?= e($eventLink) ?>">Se stævner</a></div><?php endif; ?>
    <?php foreach ([true, false] as $future):
        $entries = array_values(array_filter($shifts, fn (array $shift): bool => ($shift['ends_at'] > time()) === $future));
        if (!$entries) { continue; }
        if ($future) { $entries = array_reverse($entries); }
    ?>
    <h2><?= $future ? 'Kommende vagter' : 'Tidligere vagter' ?></h2>
    <div class="panel list-panel">
        <?php foreach ($entries as $shift): ?><div class="listing">
            <span class="tile"><?= icon('calendar') ?></span><div><h3><?= e($shift['name']) ?></h3>
            <p><?= e($shift['event_name']) ?> · <?= e($shift['organizer_name']) ?> · <?= e($shift['group_name']) ?></p><p><?= e(shiftDate($shift)) ?></p></div>
        </div><?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</section>
<?php elseif ($page === 'notices'):
    $notices = query('SELECT * FROM notices WHERE user_id=? ORDER BY created_at DESC,id DESC', [$user['id']])->fetchAll();
?>
<section class="interior">
    <div class="interior-heading"><h1>Notifikationer</h1><a class="text-link" href="?page=profile#profile-notifications">Indstillinger</a><?php if ($unread): ?><form method="post"><?php csrf('read_notices'); ?><button class="button secondary" type="submit">Markér alle som læst</button></form><?php endif; ?></div>
    <?php if (!$notices): ?><div class="panel empty-panel"><h2>Ingen notifikationer endnu</h2></div><?php else: ?>
    <div class="panel list-panel">
        <?php foreach ($notices as $notice): ?><div class="listing"><span class="tile"><?= icon('bell') ?></span><div>
            <h3><?= e($notice['title']) ?> <?= $notice['read_at'] === null ? '<span class="status available">Ulæst</span>' : '' ?></h3>
            <p><?= e($notice['body']) ?></p><time datetime="<?= e(date(DATE_ATOM, $notice['created_at'])) ?>"><?= e(dayLabel(date('Y-m-d', $notice['created_at']))) ?> kl. <?= e(date('H:i', $notice['created_at'])) ?></time>
        </div></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php else: ?>
<section class="interior">
    <h1>Administratoradgang</h1>
    <?php if (!$user): ?><p>Log ind for at administrere stævner.</p><a class="button primary" href="?page=login">Log ind</a>
    <?php elseif ($user['organizer_id'] && !$user['organizer_active']): ?><p>Din arrangøradgang er sat på pause.</p>
    <?php else: ?><p>Du har ikke adgang til den valgte side.</p><?php endif; ?>
</section>
<?php endif; ?>
