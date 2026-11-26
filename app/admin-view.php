<?php
$adminLink = '?page=admin&event=' . $eventId;
$eventForm = $event ?? ['name'=>'','discipline'=>'spring','location'=>'','starts_on'=>'','ends_on'=>'','description'=>'','visible'=>0];
$groupList = $event ? query('SELECT * FROM `groups` WHERE event_id=? ORDER BY id', [$eventId])->fetchAll() : [];
$selectedOrganizer = $event['organizer_id'] ?? ($organizerFilter ?: null);
$applications = $event ? query("SELECT a.*,u.name,u.email FROM helper_applications a JOIN users u ON u.id=a.user_id
    WHERE a.event_id=? ORDER BY a.requested_at,a.user_id", [$eventId])->fetchAll() : [];
$pending = array_filter($applications, fn(array $entry): bool => $entry['status'] === 'pending');
$usage = !$event && !empty($user['organizer_id']) ? billingUsage($user['organizer_id']) : null;
$needsPayment = $usage && !$usage['free'] && !$usage['credits'] && $usage['annual'] <= time();
?>
<section class="interior admin-page">
    <div class="page-heading">
        <div><h1>Stævneadmin</h1><p><?= $isAdmin ? 'Stævner, vagter og tilmeldte hjælpere' : e($user['organizer_name']) ?></p></div>
        <div class="admin-tools">
            <?php if (($isAdmin && count($organizerOptions) > 1) || $events): ?>
            <div class="event-controls">
                <?php if ($isAdmin && count($organizerOptions) > 1): ?><?php organizerFilterForm($organizerOptions, $organizerFilter, true); ?><?php endif; ?>
                <?php if ($events): ?>
                <form class="event-picker" method="get">
                    <input type="hidden" name="page" value="admin">
                    <?php if ($organizerFilter): ?><input type="hidden" name="organizer" value="<?= $organizerFilter ?>"><?php endif; ?>
                    <label for="admin-event">Vælg stævne</label><div class="select-wrap"><select id="admin-event" name="event" data-auto-submit>
                        <?php if (!$event): ?><option value="0">Nyt stævne</option><?php endif; ?>
                        <?php foreach ($events as $option): ?><option value="<?= $option['id'] ?>" <?= $eventId === $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?><?= $isAdmin && !$organizerFilter && count($organizerOptions) > 1 ? ' · ' . e($option['organizer_name']) : '' ?><?= $option['visible'] ? '' : ' (skjult)' ?></option><?php endforeach; ?>
                    </select><?= icon('chevron', 16) ?></div><noscript><button class="button secondary small">Vis stævne</button></noscript>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($event && $pending): ?><a class="button secondary" href="#ansoegninger">Ansøgninger (<?= count($pending) ?>)</a><?php endif; ?>
            <?php if ($event): ?><a class="button primary" href="?page=admin&amp;event=0<?= $organizerFilter ? '&amp;organizer=' . $organizerFilter : '' ?>"><?= icon('plus', 18) ?> Nyt stævne</a><?php endif; ?>
        </div>
    </div>
    <?php if ($needsPayment): ?>
        <section class="panel admin-section event-create">
            <h2>Opret stævne</h2>
            <p>I har brugt de tre gratis stævner. Køb ét ekstra stævne eller årsadgang.</p>
            <a class="button primary" href="?page=billing">Se betalingsmuligheder</a>
        </section>
    <?php elseif (!$event): ?>
        <section class="panel admin-section event-create">
            <h2>Opret stævne</h2>
            <?php eventAdminForm($adminLink, $eventForm, $eventId, $isAdmin, $organizerOptions, $selectedOrganizer); ?>
        </section>
    <?php else:
        $counts = row('SELECT COUNT(DISTINCT v.id) AS posts, COUNT(s.id) AS bookings
            FROM `groups` g JOIN shifts v ON v.group_id=g.id LEFT JOIN signups s ON s.shift_id=v.id WHERE g.event_id=?', [$eventId]);
        metricStrip([
            ['clipboard', 'Vagtgrupper', count($groupList)],
            ['flag', 'Poster', (int) $counts['posts']],
            ['users', 'Tilmeldinger', (int) $counts['bookings']],
        ], 'Stævnets vagter');
    ?>
    <div class="admin-grid">
            <section aria-labelledby="groups-heading" class="panel admin-groups">
                <header class="panel-heading"><div><h2 id="groups-heading">Vagtgrupper og poster</h2><p>Se tilmeldinger og redigér vagterne for stævnet.</p></div></header>
                <?php foreach ($groupList as $index => $group):
                    $shifts = query('SELECT * FROM shifts WHERE group_id=? ORDER BY starts_at,id', [$group['id']])->fetchAll();
                ?>
                <details class="admin-details" <?= $index === 0 ? 'open' : '' ?>>
                    <summary><span class="tile"><?= icon(groupIcon($group['name'])) ?></span><strong><?= e($group['name']) ?></strong><span class="status <?= $group['visible'] ? 'available' : '' ?>"><?= $group['visible'] ? 'Synlig' : 'Skjult' ?></span><?= icon('chevron', 17) ?></summary>
                    <div class="admin-details-body">
                        <div class="admin-shifts">
                        <?php foreach ($shifts as $shift):
                            $signups = query('SELECT s.id,u.name,u.email FROM signups s JOIN users u ON u.id=s.user_id WHERE s.shift_id=? ORDER BY s.created_at,s.id', [$shift['id']])->fetchAll();
                        ?>
                        <details class="nested-details">
                            <summary><span><strong><?= e($shift['name']) ?></strong><span class="muted shift-date-line"><?= e(shiftDate($shift)) ?></span></span><span class="status"><?= count($signups) ?>/<?= $shift['capacity'] ?> tilmeldt<?= !$shift['visible'] ? ' · skjult' : (!$shift['bookable'] ? ' · lukket' : '') ?></span><?= icon('chevron', 16) ?></summary>
                            <div class="nested-body">
                                <?php shiftForm($adminLink, $group['id'], $shift, $event['starts_on'], $eventId); ?>
                                <h4>Tilmeldte hjælpere</h4>
                                <?php if (!$signups): ?><p>Ingen tilmeldte endnu.</p><?php endif; ?>
                                <?php foreach ($signups as $signup): ?>
                                    <div class="signup-row"><div><strong><?= e($signup['name']) ?></strong><br><a class="text-link" href="mailto:<?= e($signup['email']) ?>"><?= e($signup['email']) ?></a></div>
                                    <form method="post" action="<?= e($adminLink) ?>" data-confirm="Afmeld hjælperen fra denne vagt?"><?php csrf('cancel', $signup['id']); ?><button class="button danger small" type="submit">Afmeld</button></form></div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endforeach; ?>
                        <details class="nested-details">
                            <summary><strong><?= icon('plus', 17) ?> Tilføj post</strong><?= icon('chevron', 16) ?></summary>
                            <div class="nested-body"><?php shiftForm($adminLink, $group['id'], null, $event['starts_on'], $eventId); ?></div>
                        </details>
                        </div>
                        <details class="nested-details group-settings">
                            <summary><strong><?= icon('settings', 16) ?> Redigér vagtgruppe</strong><?= icon('chevron', 16) ?></summary>
                            <div class="nested-body"><?php groupForm($adminLink, $group, $eventId); ?></div>
                        </details>
                    </div>
                </details>
                <?php endforeach; ?>
                <details class="admin-details add-group">
                    <summary><span class="tile"><?= icon('plus') ?></span><strong>Tilføj vagtgruppe</strong><?= icon('chevron', 17) ?></summary>
                    <div class="admin-details-body"><?php groupForm($adminLink, null, $eventId); ?></div>
                </details>
                <datalist id="standard-grupper"><option>Bane &amp; forhindringer</option><option>Sekretariat &amp; indtjek</option><option>Cafeteria</option><option>Parkering &amp; velkomst</option></datalist>
                <datalist id="standard-poster"><option>Banepersonale</option><option>Banebygning</option><option>Indtjek og sekretariat</option><option>Skriver ved dommerbordet</option><option>Resultater og præmier</option><option>Cafeteria</option><option>Parkeringshjælp</option></datalist>
            </section>
        <?php
            $contacts = query('SELECT * FROM contacts WHERE event_id=? ORDER BY LOWER(name),id', [$eventId])->fetchAll();
        ?>
        <aside class="sidebar">
        <?php require __DIR__ . '/admin-applications-view.php'; ?>
        <details class="panel settings-card">
            <summary class="panel-heading">
                <span class="tile"><?= icon('settings', 19) ?></span>
                <span><strong>Stævneoplysninger</strong><small><?= e($event['name']) ?></small></span>
                <?= icon('chevron', 16) ?>
            </summary>
            <div class="admin-section">
                <?php eventAdminForm($adminLink, $eventForm, $eventId, $isAdmin, $organizerOptions, $selectedOrganizer); ?>
            </div>
        </details>
        <section class="panel admin-section" aria-labelledby="helpers-export">
            <span class="tile"><?= icon('clipboard') ?></span>
            <h2 id="helpers-export">Hjælperoversigt</h2>
            <p>Hent vagtgrupper, vagttider, tilmeldte hjælpere og antal pladser for <?= e($event['name']) ?>.</p>
            <div class="admin-actions">
                <a class="button secondary small" href="?page=export&amp;event=<?= $eventId ?>&amp;format=pdf"><?= icon('download', 15) ?> Hent PDF</a>
                <a class="button secondary small" href="?page=export&amp;event=<?= $eventId ?>&amp;format=xlsx"><?= icon('download', 15) ?> Hent Excel</a>
            </div>
        </section>
        <section class="panel admin-section" id="kontakter">
            <span class="tile"><?= icon('users') ?></span><h2>Eksterne hjælpere</h2>
            <p>Privat kontaktliste ved <?= e($event['name']) ?>.</p>
            <?php foreach ($contacts as $contact): ?>
                <details class="nested-details contact-details"><summary><span><strong><?= e($contact['name']) ?></strong><span class="muted shift-date-line"><?= e($contact['task']) ?></span></span><?= icon('chevron', 16) ?></summary><div class="nested-body"><?php contactForm($adminLink, $contact); ?></div></details>
            <?php endforeach; ?>
            <details class="nested-details"><summary><strong><?= icon('plus', 17) ?> Tilføj kontakt</strong><?= icon('chevron', 16) ?></summary><div class="nested-body"><?php contactForm($adminLink, null); ?></div></details>
        </section>
        </aside>
    </div>
    <?php endif; ?>
</section>
<?php

function eventAdminForm(string $adminLink, array $eventForm, int $eventId, bool $isAdmin, array $organizerOptions, ?int $selectedOrganizer): void
{
    $event = $eventId > 0;
    ?>
    <form class="form-stack" method="post" action="<?= e($adminLink) ?>">
        <?php csrf('event', $eventId); ?>
        <?php if ($isAdmin): ?>
            <label>Arrangør<span class="select-wrap"><select name="organizer_id" required>
                <?php foreach ($organizerOptions as $option): ?><option value="<?= $option['id'] ?>" <?= $selectedOrganizer === $option['id'] || ($selectedOrganizer === null && $option['owner_id'] === null) ? 'selected' : '' ?>><?= e($option['name']) ?><?= $option['active'] ? '' : ' (sat på pause)' ?></option><?php endforeach; ?>
            </select><?= icon('chevron', 16) ?></span></label>
            <?php if ($event && count($organizerOptions) > 1): ?><p class="form-hint">Ved skift af arrangør får den nye arrangør adgang til stævnets tilmeldinger og kontaktliste.</p><?php endif; ?>
        <?php endif; ?>
        <label>Stævnenavn<input name="name" value="<?= e($eventForm['name']) ?>" required maxlength="160"></label>
        <div class="form-grid">
            <label>Disciplin<span class="select-wrap"><select name="discipline"><?php foreach (disciplines() as $value=>$label): ?><option value="<?= e($value) ?>" <?= $eventForm['discipline'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><?= icon('chevron', 16) ?></span></label>
            <label>Sted<input name="location" value="<?= e($eventForm['location']) ?>" required maxlength="200"></label>
            <label>Første stævnedag<input name="starts_on" type="date" value="<?= e($eventForm['starts_on']) ?>" required></label>
            <label>Sidste stævnedag<input name="ends_on" type="date" value="<?= e($eventForm['ends_on']) ?>" required></label>
        </div>
        <label>Beskrivelse<textarea name="description" maxlength="2500" rows="3"><?= e($eventForm['description']) ?></textarea></label>
        <label class="checkbox"><input type="checkbox" name="visible" value="1" <?= $eventForm['visible'] ? 'checked' : '' ?>> Vis stævnet for hjælpere</label>
        <?php if (!$event): ?><label class="checkbox"><input type="checkbox" name="presets" value="1" checked> Tilføj skjulte standardgrupper og poster til disciplinen</label><?php endif; ?>
        <div class="admin-actions">
            <button class="button primary" type="submit"><?= $event ? 'Gem stævne' : 'Opret stævne' ?></button>
            <?php if ($event): ?><?php deleteLink($eventId, 'event', $eventId, 'Slet stævne'); ?><?php endif; ?>
        </div>
    </form>
    <?php
}

function deleteLink(int $event, string $kind, int $id, string $label): void
{
    ?>
    <a class="button danger small" href="?page=delete&amp;event=<?= $event ?>&amp;kind=<?= e($kind) ?>&amp;id=<?= $id ?>"><?= e($label) ?></a>
    <?php
}

function groupForm(string $action, ?array $group, int $eventId): void
{
    $id = $group['id'] ?? 0;
    $images = $group ? array_column(query('SELECT id,position FROM images WHERE group_id=?', [$id])->fetchAll(), 'id', 'position') : [];
    ?>
    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form-stack">
        <?php csrf('group', $id); ?>
        <label>Gruppenavn<input name="name" list="standard-grupper" required maxlength="160" value="<?= e($group['name'] ?? '') ?>"></label>
        <label>Jobbeskrivelse<textarea name="description" maxlength="3000" rows="3"><?= e($group['description'] ?? '') ?></textarea></label>
        <div class="form-grid">
        <?php for ($position=1; $position<=2; $position++): ?>
            <div class="image-field">
                <?php if (isset($images[$position])): ?><img src="?page=image&amp;id=<?= $images[$position] ?>" alt="Billede <?= $position ?>"><?php endif; ?>
                <label>Billede <?= $position ?><input type="file" name="image<?= $position ?>" accept="image/jpeg,image/png,image/webp"></label>
                <?php if (isset($images[$position])): ?><label class="checkbox"><input name="remove_image<?= $position ?>" type="checkbox" value="1"> Fjern billede</label><?php endif; ?>
            </div>
        <?php endfor; ?>
        </div>
        <p class="form-hint">JPG, PNG eller WebP. Højst 3 MB pr. billede.</p>
        <label class="checkbox"><input type="checkbox" name="visible" value="1" <?= ($group['visible'] ?? 1) ? 'checked' : '' ?>> Vis gruppen for hjælpere</label>
        <div class="admin-actions">
            <button class="button primary small" type="submit"><?= $group ? 'Gem gruppe' : 'Opret gruppe' ?></button>
            <?php if ($group): ?><?php deleteLink($eventId, 'group', $group['id'], 'Slet gruppe'); ?><?php endif; ?>
        </div>
    </form>
    <?php
}

function shiftForm(string $action, int $groupId, ?array $shift, string $eventDate, int $eventId): void
{
    ?>
    <form method="post" action="<?= e($action) ?>" class="form-stack">
        <?php csrf('shift', $shift['id'] ?? 0); ?>
        <input type="hidden" name="group_id" value="<?= $groupId ?>">
        <label>Postens navn<input name="name" list="standard-poster" maxlength="160" required value="<?= e($shift['name'] ?? '') ?>"></label>
        <div class="form-grid">
            <label>Start<input name="starts_at" type="datetime-local" required value="<?= e($shift ? date('Y-m-d\TH:i', $shift['starts_at']) : $eventDate . 'T09:00') ?>"></label>
            <label>Slut<input name="ends_at" type="datetime-local" required value="<?= e($shift ? date('Y-m-d\TH:i', $shift['ends_at']) : $eventDate . 'T12:00') ?>"></label>
        </div>
        <label>Antal pladser<input name="capacity" type="number" min="1" max="10000" required value="<?= $shift['capacity'] ?? 1 ?>"></label>
        <div class="form-grid">
            <label class="checkbox"><input type="checkbox" name="visible" value="1" <?= ($shift['visible'] ?? 1) ? 'checked' : '' ?>> Vis posten</label>
            <label class="checkbox"><input type="checkbox" name="bookable" value="1" <?= ($shift['bookable'] ?? 1) ? 'checked' : '' ?>> Åbn for tilmelding</label>
        </div>
        <div class="admin-actions">
            <button class="button primary small" type="submit"><?= $shift ? 'Gem post' : 'Opret post' ?></button>
            <?php if ($shift): ?><?php deleteLink($eventId, 'shift', $shift['id'], 'Slet post'); ?><?php endif; ?>
        </div>
    </form>
    <?php
}

function contactForm(string $action, ?array $contact): void
{
    ?>
    <form method="post" action="<?= e($action) ?>" class="form-stack">
        <?php csrf('contact', $contact['id'] ?? 0); ?>
        <label>Navn<input name="name" maxlength="120" required value="<?= e($contact['name'] ?? '') ?>"></label>
        <label>Kontakt<input name="contact" maxlength="254" required value="<?= e($contact['contact'] ?? '') ?>" placeholder="Telefon eller e-mail"></label>
        <label>Opgave<textarea name="task" maxlength="500" rows="2" required><?= e($contact['task'] ?? '') ?></textarea></label>
        <div><button class="button primary small" type="submit"><?= $contact ? 'Gem kontakt' : 'Tilføj kontakt' ?></button></div>
    </form>
    <?php
}
