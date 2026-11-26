<?php
[$label, $definite, $impact] = match ($deleteKind) {
    'event' => ['stævne', 'stævnet', 'Alle grupper, poster, billeder, tilmeldinger og den private kontaktliste slettes.'],
    'group' => ['gruppe', 'gruppen', 'Gruppens poster, billeder og tilmeldinger slettes.'],
    'shift' => ['post', 'posten', 'Postens tilmeldinger slettes.'],
};
?>
<section class="panel auth-card delete-card" aria-labelledby="delete-title">
    <h1 id="delete-title">Slet <?= $label ?></h1>
    <p><strong><?= e($deleteTarget['name']) ?></strong><?php if ($deleteKind !== 'event'): ?> · <?= e($event['name']) ?><?php endif; ?></p>
    <p><?= $impact ?> Tilmeldte hjælpere får besked.</p>
    <form class="form-stack" method="post" action="?page=delete&amp;event=<?= $event['id'] ?>">
        <?php csrf('delete', $deleteTarget['id']); ?>
        <input type="hidden" name="kind" value="<?= e($deleteKind) ?>">
        <input type="hidden" name="confirmation" value="<?= e(deletionConfirmation($event, $deleteTarget, $deleteKind)) ?>">
        <label class="checkbox"><input type="checkbox" name="confirm_delete" value="1" required> Jeg vil slette <?= $definite ?> permanent</label>
        <div class="admin-actions">
            <a href="?page=admin&amp;event=<?= $event['id'] ?>" class="button secondary">Annullér</a>
            <button type="submit" class="button danger">Slet <?= $label ?></button>
        </div>
    </form>
</section>
