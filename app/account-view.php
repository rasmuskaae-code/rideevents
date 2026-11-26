<?php if ($page === 'login' || $page === 'register'):
    $register = $page === 'register';
?>
<section class="panel auth-card">
    <span class="tile"><?= icon('user', 22) ?></span>
    <h1><?= $register ? 'Opret bruger' : 'Log ind' ?></h1>
    <p><?= $register ? 'Opret din profil, så du kan melde dig som hjælper.' : 'Log ind for at tilmelde dig og se dine vagter.' ?></p>
    <form method="post" class="form-stack">
        <?php csrf($page); ?>
        <?php if ($register): ?><label>Dit navn<input name="name" autocomplete="name" maxlength="120" required></label><?php endif; ?>
        <label>E-mail<input type="email" name="email" autocomplete="email" maxlength="254" required></label>
        <label>Adgangskode<input type="password" name="password" autocomplete="<?= $register ? 'new-password' : 'current-password' ?>" <?= $register ? 'minlength="8" aria-describedby="password-help"' : '' ?> maxlength="72" required></label>
        <?php if ($register): ?><p class="form-hint" id="password-help">Brug mindst 8 tegn.</p><?php endif; ?>
        <button class="button primary" type="submit"><?= $register ? 'Opret bruger' : 'Log ind' ?></button>
    </form>
    <div class="auth-switch"><?= $register ? 'Har du allerede en profil?' : 'Har du ingen profil endnu?' ?> <a class="text-link" href="?page=<?= $register ? 'login' : 'register' ?>&amp;event=<?= $eventId ?>"><?= $register ? 'Log ind' : 'Opret bruger' ?></a></div>
</section>
<?php elseif ($page === 'mine'):
    $shifts = query('SELECT v.*,g.name AS group_name,g.id AS group_id,e.name AS event_name FROM signups s JOIN shifts v ON v.id=s.shift_id
        JOIN groups g ON g.id=v.group_id JOIN events e ON e.id=g.event_id WHERE s.user_id=? ORDER BY v.starts_at DESC,v.id DESC', [$user['id']])->fetchAll();
?>
<section class="interior">
    <h1>Mine vagter</h1><p>Kontakt stævnets arrangør, hvis du skal afmeldes en vagt.</p>
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
            <p><?= e($shift['event_name']) ?> · <?= e($shift['group_name']) ?></p><p><?= e(shiftDate($shift)) ?></p></div>
        </div><?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</section>
<?php elseif ($page === 'notices'):
    $notices = query('SELECT * FROM notices WHERE user_id=? ORDER BY created_at DESC,id DESC', [$user['id']])->fetchAll();
?>
<section class="interior">
    <div class="interior-heading"><h1>Notifikationer</h1><?php if ($unread): ?><form method="post"><?php csrf('read_notices'); ?><button class="button secondary" type="submit">Markér alle som læst</button></form><?php endif; ?></div>
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
<section class="interior"><h1>Administratoradgang</h1><p>Log ind med en administratorkonto for at administrere stævner.</p><a class="button primary" href="?page=login">Log ind</a></section>
<?php endif; ?>
