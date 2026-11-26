<?php
$adminLink = '?page=admin&event=' . $eventId;
$eventForm = $event ?? ['name'=>'','discipline'=>'spring','location'=>'','starts_on'=>'','ends_on'=>'','description'=>'','visible'=>0];
$groupList = $event ? query('SELECT * FROM groups WHERE event_id=? ORDER BY id', [$eventId])->fetchAll() : [];
$selectedOrganizer = $event['organizer_id'] ?? ($organizerFilter ?: null);
?>
<section class="interior admin-page">
    <div class="interior-heading">
        <div><h1><?= $isAdmin ? 'Stævneadministration' : e($user['organizer_name']) ?></h1><p>Administrér stævner, vagter og tilmeldte hjælpere.</p></div>
        <?php if ($event): ?><a class="button primary" href="?page=admin&amp;event=0<?= $organizerFilter ? '&amp;organizer=' . $organizerFilter : '' ?>"><?= icon('plus', 18) ?> Nyt stævne</a><?php endif; ?>
    </div>
    <?php if (($isAdmin && count($organizerOptions) > 1) || $events): ?>
    <div class="event-controls admin-picker">
        <?php if ($isAdmin && count($organizerOptions) > 1): ?><?php organizerFilterForm($organizerOptions, $organizerFilter, true); ?><?php endif; ?>
        <?php if ($events): ?>
        <form class="event-picker" method="get">
            <input type="hidden" name="page" value="admin">
            <?php if ($organizerFilter): ?><input type="hidden" name="organizer" value="<?= $organizerFilter ?>"><?php endif; ?>
            <label for="admin-event">Vælg stævne</label><div class="select-wrap"><select id="admin-event" name="event" data-auto-submit>
                <?php if (!$event): ?><option value="0">Nyt stævne</option><?php endif; ?>
                <?php foreach ($events as $option): ?><option value="<?= $option['id'] ?>" <?= $eventId === $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?><?= $isAdmin && !$organizerFilter ? ' · ' . e($option['organizer_name']) : '' ?><?= $option['visible'] ? '' : ' (skjult)' ?></option><?php endforeach; ?>
            </select><?= icon('chevron', 16) ?></div><noscript><button class="button secondary small">Vis stævne</button></noscript>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="admin-grid">
        <div>
            <section class="panel admin-section">
                <h2><?= $event ? 'Stævneoplysninger' : 'Opret stævne' ?></h2>
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
                    <div><button class="button primary" type="submit"><?= $event ? 'Gem stævne' : 'Opret stævne' ?></button></div>
                </form>
            </section>
            <?php if ($event): ?>
            <section aria-labelledby="groups-heading" class="admin-groups">
                <h2 id="groups-heading">Vagtgrupper og poster</h2>
                <?php foreach ($groupList as $group):
                    $shifts = query('SELECT * FROM shifts WHERE group_id=? ORDER BY starts_at,id', [$group['id']])->fetchAll();
                ?>
                <details class="panel admin-details">
                    <summary><span class="tile"><?= icon(groupIcon($group['name'])) ?></span><strong><?= e($group['name']) ?></strong><span class="status <?= $group['visible'] ? 'available' : '' ?>"><?= $group['visible'] ? 'Synlig' : 'Skjult' ?></span><?= icon('chevron', 17) ?></summary>
                    <div class="admin-details-body">
                        <?php groupForm($adminLink, $group); ?>
                        <div class="admin-shifts">
                        <h3>Poster</h3>
                        <?php foreach ($shifts as $shift):
                            $signups = query('SELECT s.id,u.name,u.email FROM signups s JOIN users u ON u.id=s.user_id WHERE s.shift_id=? ORDER BY s.created_at,s.id', [$shift['id']])->fetchAll();
                        ?>
                        <details class="nested-details">
                            <summary><span><strong><?= e($shift['name']) ?></strong><span class="muted shift-date-line"><?= e(shiftDate($shift)) ?></span></span><span class="status"><?= count($signups) ?>/<?= $shift['capacity'] ?> tilmeldt<?= !$shift['visible'] ? ' · skjult' : (!$shift['bookable'] ? ' · lukket' : '') ?></span><?= icon('chevron', 16) ?></summary>
                            <div class="nested-body">
                                <?php shiftForm($adminLink, $group['id'], $shift, $event['starts_on']); ?>
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
                            <div class="nested-body"><?php shiftForm($adminLink, $group['id'], null, $event['starts_on']); ?></div>
                        </details>
                        </div>
                    </div>
                </details>
                <?php endforeach; ?>
                <details class="panel admin-details">
                    <summary><span class="tile"><?= icon('plus') ?></span><strong>Tilføj vagtgruppe</strong><?= icon('chevron', 17) ?></summary>
                    <div class="admin-details-body"><?php groupForm($adminLink, null); ?></div>
                </details>
                <datalist id="standard-grupper"><option>Bane &amp; forhindringer</option><option>Sekretariat &amp; indtjek</option><option>Cafeteria</option><option>Parkering &amp; velkomst</option></datalist>
                <datalist id="standard-poster"><option>Banepersonale</option><option>Banebygning</option><option>Indtjek og sekretariat</option><option>Skriver ved dommerbordet</option><option>Resultater og præmier</option><option>Cafeteria</option><option>Parkeringshjælp</option></datalist>
            </section>
            <?php endif; ?>
        </div>
        <?php if ($event):
            $contacts = query('SELECT * FROM contacts WHERE event_id=? ORDER BY name COLLATE NOCASE,id', [$eventId])->fetchAll();
        ?>
        <aside class="panel admin-section" id="kontakter">
            <span class="tile"><?= icon('users') ?></span><h2>Eksterne hjælpere</h2>
            <p>Privat kontaktliste ved <?= e($event['name']) ?>.</p>
            <?php foreach ($contacts as $contact): ?>
                <details class="nested-details contact-details"><summary><span><strong><?= e($contact['name']) ?></strong><span class="muted shift-date-line"><?= e($contact['task']) ?></span></span><?= icon('chevron', 16) ?></summary><div class="nested-body"><?php contactForm($adminLink, $contact); ?></div></details>
            <?php endforeach; ?>
            <details class="nested-details" <?= $contacts ? '' : 'open' ?>><summary><strong><?= icon('plus', 17) ?> Tilføj kontakt</strong><?= icon('chevron', 16) ?></summary><div class="nested-body"><?php contactForm($adminLink, null); ?></div></details>
        </aside>
        <?php endif; ?>
    </div>
</section>
<?php

function groupForm(string $action, ?array $group): void
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
        <div><button class="button primary small" type="submit"><?= $group ? 'Gem gruppe' : 'Opret gruppe' ?></button></div>
    </form>
    <?php
}

function shiftForm(string $action, int $groupId, ?array $shift, string $eventDate): void
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
        <div><button class="button primary small" type="submit"><?= $shift ? 'Gem post' : 'Opret post' ?></button></div>
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
