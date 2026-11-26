<?php if (!$event): ?>
    <div class="page-heading"><div><h1>Stævner</h1><p>Vælg et stævne og find en vagt, der passer dig.</p></div></div>
    <section class="panel empty-panel"><span class="tile"><?= icon('calendar') ?></span><h2>Ingen stævner at vise</h2><p>Her kan du se stævnerne, når arrangøren har åbnet for hjælpere.</p><?php if ($canManage): ?><a class="button primary" href="?page=admin&amp;event=0">Opret stævne</a><?php endif; ?></section>
<?php else:
    $groups = query('SELECT * FROM `groups` WHERE event_id=? AND visible=1 ORDER BY id', [$eventId])->fetchAll();
    $shifts = query('SELECT v.*,
        (SELECT COUNT(*) FROM signups s WHERE s.shift_id=v.id) AS booked,
        (SELECT COUNT(*) FROM signups s WHERE s.shift_id=v.id AND s.user_id=?) AS mine
        FROM shifts v JOIN `groups` g ON g.id=v.group_id
        WHERE g.event_id=? AND g.visible=1 AND v.visible=1 ORDER BY v.starts_at,v.id', [$user['id'] ?? 0, $eventId])->fetchAll();
    $now = time();
    $byGroup = $openByGroup = [];
    $availableSpots = 0;
    foreach ($shifts as $shift) {
        $byGroup[$shift['group_id']][] = $shift;
        if ($shift['bookable'] && $shift['booked'] < $shift['capacity'] && $shift['starts_at'] > $now) {
            $openByGroup[$shift['group_id']] = ($openByGroup[$shift['group_id']] ?? 0) + 1;
            $availableSpots += $shift['capacity'] - $shift['booked'];
        }
    }
    $images = query('SELECT i.id,i.group_id,i.position FROM images i JOIN `groups` g ON g.id=i.group_id WHERE g.event_id=? AND g.visible=1 ORDER BY i.position', [$eventId])->fetchAll();
    $imagesByGroup = [];
    foreach ($images as $image) {
        $imagesByGroup[$image['group_id']][] = $image;
    }
?>
<div class="page-heading">
    <div><h1>Stævner</h1><p>Vælg et stævne og find en vagt, der passer dig.</p></div>
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
<?php metricStrip([
    ['flag', 'Åbne poster', array_sum($openByGroup)],
    ['users', 'Ledige pladser', $availableSpots],
    ['clipboard', 'Vagtgrupper', count($groups)],
], 'Vagtoversigt'); ?>
<div class="columns">
    <section class="panel groups" aria-labelledby="shift-groups-title">
        <header class="panel-heading"><div><h2 id="shift-groups-title">Vagtgrupper</h2><p>Åbn en vagtgruppe for at se poster og mødetider.</p></div></header>
        <?php if (!$groups): ?><div class="group-empty"><p>Der er ingen synlige vagtgrupper endnu.</p></div><?php endif; ?>
        <?php foreach ($groups as $index => $group):
            $groupShifts = $byGroup[$group['id']] ?? [];
            $open = $openByGroup[$group['id']] ?? 0;
        ?>
        <details class="group-card" <?= $index === 0 ? 'open' : '' ?>>
            <summary><span class="tile"><?= icon(groupIcon($group['name'])) ?></span><span class="group-title"><?= e($group['name']) ?></span><span class="group-count"><?= $open ?> <?= $open === 1 ? 'åben post' : 'åbne poster' ?></span><span class="chevron"><?= icon('chevron', 18) ?></span></summary>
            <div class="group-body">
                <?php if ($group['description'] !== ''): ?><p class="job-description"><?= nl2br(e($group['description'])) ?></p><?php endif; ?>
                <?php if (isset($imagesByGroup[$group['id']])): ?><div class="job-images"><?php foreach ($imagesByGroup[$group['id']] as $image): ?><a href="?page=image&amp;id=<?= $image['id'] ?>"><img loading="lazy" src="?page=image&amp;id=<?= $image['id'] ?>" alt="<?= e($group['name']) ?> – billede <?= $image['position'] ?>"></a><?php endforeach; ?></div><?php endif; ?>
                <?php if ($groupShifts): ?>
                    <div class="shift-table-head" aria-hidden="true"><span>Post</span><span>Tidspunkt</span><span>Pladser</span><span></span></div>
                <?php else: ?><p class="group-empty">Der er ingen synlige poster i gruppen.</p><?php endif; ?>
                <?php foreach ($groupShifts as $shift):
                    $spots = $shift['capacity'] - $shift['booked'];
                    $over = $shift['starts_at'] <= $now;
                    $canJoin = !$over && $shift['bookable'] && $spots > 0;
                ?>
                <div class="shift">
                    <h3 class="shift-name"><?= e($shift['name']) ?></h3>
                    <span class="shift-time"><?= icon('clock', 14) ?> <?= e(shiftDate($shift)) ?></span>
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
            <header class="panel-heading"><h2>Stævneoplysninger</h2></header>
            <?php if ($event['discipline'] !== 'dressur'): ?>
                <img class="event-photo" src="assets/stadium.webp" alt="" width="340" height="141">
            <?php endif; ?>
            <div class="event-card-body">
                <div class="event-title"><h3><?= e($event['name']) ?></h3><span class="discipline"><?= e(disciplines()[$event['discipline']]) ?></span></div>
                <dl class="event-meta">
                    <div><dt><?= icon('users', 17) ?> Arrangør</dt><dd><?= e($event['organizer_name']) ?></dd></div>
                    <div><dt><?= icon('calendar', 17) ?> Dato</dt><dd><?= e(eventDates($event)) ?></dd></div>
                    <div><dt><?= icon('pin', 17) ?> Sted</dt><dd><?= e($event['location']) ?></dd></div>
                </dl>
                <?php if ($event['description'] !== ''): ?><p><?= nl2br(e($event['description'])) ?></p><?php endif; ?>
            </div>
        </section>
        <?php if (!$user): ?><section class="panel info-card">
            <span class="tile"><?= icon('user', 20) ?></span>
            <div>
                <h2>Første gang som hjælper?</h2>
                <p>Med en profil kan du tilmelde dig og få besked om ændringer.</p>
                <a class="text-link" href="?page=register&amp;event=<?= $eventId ?>">Opret bruger</a>
            </div>
        </section><?php endif; ?>
    </aside>
</div>
<?php endif; ?>
