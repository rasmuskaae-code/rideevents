<section class="hero" aria-labelledby="hero-title">
    <div class="hero-content">
        <p class="hero-label"><?= icon('flag', 16) ?> Hjælperportalen</p>
        <h1 id="hero-title">Find din næste<br> stævnevagt</h1>
        <p>Vælg et stævne, find en opgave, og meld dig som hjælper.</p>
    </div>
</section>

<?php if (!$event): ?>
    <section class="panel empty-panel"><span class="tile"><?= icon('calendar') ?></span><h2>Ingen stævner at vise</h2><p>Her kan du se stævnerne, når arrangøren har åbnet for hjælpere.</p><?php if ($canManage): ?><a class="button primary" href="?page=admin&amp;event=0">Opret stævne</a><?php endif; ?></section>
<?php else:
    $groups = query('SELECT * FROM `groups` WHERE event_id=? AND visible=1 ORDER BY id', [$eventId])->fetchAll();
    $shifts = query('SELECT v.*,
        (SELECT COUNT(*) FROM signups s WHERE s.shift_id=v.id) AS booked,
        (SELECT COUNT(*) FROM signups s WHERE s.shift_id=v.id AND s.user_id=?) AS mine
        FROM shifts v JOIN `groups` g ON g.id=v.group_id
        WHERE g.event_id=? AND g.visible=1 AND v.visible=1 ORDER BY v.starts_at,v.id', [$user['id'] ?? 0, $eventId])->fetchAll();
    $byGroup = [];
    foreach ($shifts as $shift) {
        $byGroup[$shift['group_id']][] = $shift;
    }
    $images = query('SELECT i.id,i.group_id,i.position FROM images i JOIN `groups` g ON g.id=i.group_id WHERE g.event_id=? AND g.visible=1 ORDER BY i.position', [$eventId])->fetchAll();
    $imagesByGroup = [];
    foreach ($images as $image) {
        $imagesByGroup[$image['group_id']][] = $image;
    }
?>
<div class="event-toolbar">
    <div><h2>Vagter til stævnet</h2><p>Åbn en gruppe for at se opgaver og ledige pladser.</p></div>
    <div class="event-controls">
    <?php if (count($organizerOptions) > 1): ?><?php organizerFilterForm($organizerOptions, $organizerFilter, false); ?><?php endif; ?>
    <form method="get" class="event-picker">
        <?php if ($organizerFilter): ?><input type="hidden" name="organizer" value="<?= $organizerFilter ?>"><?php endif; ?>
        <label for="event">Vælg stævne</label>
        <div class="select-wrap with-icon"><?= icon('calendar', 17) ?><select id="event" name="event" data-auto-submit>
            <?php foreach ($events as $option): ?><option value="<?= $option['id'] ?>" <?= $eventId === $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?><?= !$organizerFilter && count($organizerOptions) > 1 ? ' · ' . e($option['organizer_name']) : '' ?><?= $option['ends_on'] < $today ? ' (afholdt)' : '' ?></option><?php endforeach; ?>
        </select><?= icon('chevron', 16) ?></div><noscript><button class="button secondary small">Vis stævne</button></noscript>
    </form>
    </div>
</div>
<div class="columns">
    <section class="groups" aria-label="Vagtgrupper">
        <?php if (!$groups): ?><div class="panel empty-panel"><h3>Der er ingen synlige vagtgrupper endnu</h3></div><?php endif; ?>
        <?php foreach ($groups as $index => $group):
            $groupShifts = $byGroup[$group['id']] ?? [];
            $open = count(array_filter($groupShifts, fn(array $v): bool => $v['bookable'] && $v['booked'] < $v['capacity'] && $v['starts_at'] > time()));
        ?>
        <details class="panel group-card" <?= $index === 0 ? 'open' : '' ?>>
            <summary><span class="tile"><?= icon(groupIcon($group['name'])) ?></span><span class="group-title"><?= e($group['name']) ?></span><span class="group-count"><?= $open ?> <?= $open === 1 ? 'åben post' : 'åbne poster' ?></span><span class="chevron"><?= icon('chevron', 18) ?></span></summary>
            <div class="group-body">
                <?php if ($group['description'] !== ''): ?><p class="job-description"><?= nl2br(e($group['description'])) ?></p><?php endif; ?>
                <?php if (isset($imagesByGroup[$group['id']])): ?><div class="job-images"><?php foreach ($imagesByGroup[$group['id']] as $image): ?><a href="?page=image&amp;id=<?= $image['id'] ?>"><img loading="lazy" src="?page=image&amp;id=<?= $image['id'] ?>" alt="<?= e($group['name']) ?> – billede <?= $image['position'] ?>"></a><?php endforeach; ?></div><?php endif; ?>
                <?php if (!$groupShifts): ?><p class="muted">Der er ingen synlige poster i gruppen.</p><?php endif; ?>
                <?php foreach ($groupShifts as $shift):
                    $spots = $shift['capacity'] - $shift['booked'];
                    $over = $shift['starts_at'] <= time();
                    $canJoin = !$over && $shift['bookable'] && $spots > 0;
                ?>
                <div class="shift">
                    <div class="shift-name"><h3><?= e($shift['name']) ?></h3><span class="shift-time"><?= icon('clock', 15) ?> <?= e(shiftDate($shift)) ?></span></div>
                    <span class="status <?= $canJoin || $shift['mine'] ? 'available' : '' ?>"><?= $shift['mine'] ? icon('check', 14) . ' Tilmeldt' : ($over ? 'Startet' : (!$shift['bookable'] ? 'Lukket' : ($spots > 0 ? $spots . ' ' . ($spots === 1 ? 'ledig plads' : 'ledige') : 'Fuldt booket'))) ?></span>
                    <?php if ($canJoin && !$shift['mine'] && !$isAdmin): ?>
                        <?php if ($user): ?><form method="post" action="<?= e($eventLink) ?>"><?php csrf('join', $shift['id']); ?><button class="button primary small" type="submit" aria-label="Tilmeld <?= e($shift['name']) ?>">Tilmeld</button></form>
                        <?php else: ?><a class="button primary small" href="?page=login&amp;event=<?= $eventId ?>" aria-label="Log ind for at tilmelde <?= e($shift['name']) ?>">Tilmeld</a><?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </section>
    <aside class="sidebar" aria-label="Stævneoplysninger">
        <section class="panel event-card">
            <div class="card-kicker"><span class="tile"><?= icon('calendar', 19) ?></span><span class="discipline"><?= e(disciplines()[$event['discipline']]) ?></span></div>
            <h2><?= e($event['name']) ?></h2>
            <div class="event-meta"><span><?= icon('users', 17) ?> <?= e($event['organizer_name']) ?></span><span><?= icon('calendar', 17) ?> <?= e(eventDates($event)) ?></span><span><?= icon('pin', 18) ?> <?= e($event['location']) ?></span></div>
            <?php if ($event['description'] !== ''): ?><p><?= nl2br(e($event['description'])) ?></p><?php endif; ?>
        </section>
        <section class="info-card">
            <span class="tile"><?= icon($user ? 'clipboard' : 'users', 20) ?></span>
            <div>
                <h2><?= $user ? 'Mine vagter' : 'Første gang som hjælper?' ?></h2>
                <p><?= $user ? 'Se dine tilmeldinger og tidspunkter.' : 'Opret en profil for at tilmelde dig en vagt og få besked om ændringer.' ?></p>
                <a class="text-link" href="?page=<?= $user ? 'mine' : 'register' ?>&amp;event=<?= $eventId ?>"><?= $user ? 'Se mine vagter' : 'Opret bruger' ?></a>
            </div>
        </section>
    </aside>
</div>
<?php endif; ?>
