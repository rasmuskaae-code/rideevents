<?php
$banner = row('SELECT event_id FROM event_banners WHERE event_id=?', [$eventId]);
$links = query('SELECT id,label,url FROM event_links WHERE event_id=? ORDER BY id', [$eventId])->fetchAll();
?>
<section class="panel admin-section event-content-settings" id="staevneindhold">
    <h2>Banner og links</h2>
    <form method="post" action="<?= e($adminLink) ?>" enctype="multipart/form-data" class="form-stack">
        <?php csrf('event-banner'); ?>
        <?php if ($banner): ?><img class="banner-preview" src="?page=banner&amp;event=<?= $eventId ?>" alt="Forhåndsvisning af stævnebanner"><?php endif; ?>
        <label>Stævnebanner<input type="file" name="banner" accept="image/jpeg,image/png,image/webp" aria-describedby="banner-help"></label>
        <p id="banner-help" class="form-hint">Brug et bredt billede i JPG, PNG eller WebP på højst 3 MB.</p>
        <?php if ($banner): ?><label class="checkbox"><input type="checkbox" name="remove_banner" value="1"> Fjern banner</label><?php endif; ?>
        <div><button class="button secondary small" type="submit">Gem banner</button></div>
    </form>
    <div class="event-content-links">
        <h3>Eksterne links</h3>
        <?php foreach ([...$links, null] as $link): ?>
            <details class="nested-details">
                <summary><strong><?= $link ? e($link['label']) : 'Tilføj eksternt link' ?></strong><?= icon('chevron', 16) ?></summary>
                <div class="nested-body">
                    <form method="post" action="<?= e($adminLink) ?>" class="form-stack">
                        <?php csrf('event-link', $link['id'] ?? 0); ?>
                        <label>Linktekst<input name="label" maxlength="120" placeholder="Fx tidsplan på Equipe" value="<?= e($link['label'] ?? '') ?>" required></label>
                        <label>Adresse<input name="url" type="url" maxlength="2048" placeholder="https://" value="<?= e($link['url'] ?? '') ?>" required></label>
                        <div class="admin-actions">
                            <button class="button primary small" type="submit">Gem link</button>
                            <?php if ($link): ?><button class="button danger small" type="submit" name="remove_link" value="1" formnovalidate>Slet link</button><?php endif; ?>
                        </div>
                    </form>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</section>
