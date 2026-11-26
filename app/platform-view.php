<?php
$organizers = query('SELECT o.*,u.name AS owner_name,u.email AS owner_email,
    (SELECT COUNT(*) FROM events e WHERE e.organizer_id=o.id) AS event_count
    FROM organizers o LEFT JOIN users u ON u.id=o.owner_id ORDER BY o.owner_id IS NOT NULL,o.name COLLATE NOCASE,o.id')->fetchAll();
?>
<section class="interior">
    <div class="interior-heading">
        <div><h1>Platformadministration</h1><p>Administrér arrangører og deres adgang til Stævnevagt.</p></div>
        <a class="button primary" href="?page=admin">Administrér stævner</a>
    </div>
    <div class="platform-grid">
        <?php foreach ($organizers as $organizer): ?>
        <section class="panel admin-section">
            <div class="card-kicker"><span class="tile"><?= icon('users') ?></span><span class="status <?= $organizer['active'] ? 'available' : '' ?>"><?= $organizer['active'] ? 'Aktiv' : 'Sat på pause' ?></span></div>
            <h2><?= e($organizer['name']) ?></h2>
            <p>
                <?php if ($organizer['owner_id'] !== null): ?>
                    <?= e($organizer['owner_name']) ?><br><a class="text-link" href="mailto:<?= e($organizer['owner_email']) ?>"><?= e($organizer['owner_email']) ?></a>
                <?php else: ?>
                    Arrangør for stævner, der administreres af platformen.
                <?php endif; ?>
            </p>
            <p><?= $organizer['event_count'] ?> <?= $organizer['event_count'] === 1 ? 'stævne' : 'stævner' ?> · <a class="text-link" href="?page=admin&amp;organizer=<?= $organizer['id'] ?>">Se stævner</a></p>
            <form method="post" class="form-stack">
                <?php csrf('save_organizer', $organizer['id']); ?>
                <label>Arrangørens navn<input name="organizer_name" required maxlength="160" value="<?= e($organizer['name']) ?>"></label>
                <?php if ($organizer['owner_id'] !== null): ?>
                    <label class="checkbox"><input type="checkbox" name="active" value="1" <?= $organizer['active'] ? 'checked' : '' ?>> Arrangøren er aktiv</label>
                    <p class="form-hint">Sæt arrangøren på pause for at skjule stævnerne og stoppe nye tilmeldinger.</p>
                <?php endif; ?>
                <div><button type="submit" class="button secondary small">Gem arrangør</button></div>
            </form>
        </section>
        <?php endforeach; ?>
    </div>
</section>
