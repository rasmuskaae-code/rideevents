<?php
$decided = array_filter($applications, fn(array $entry): bool => $entry['status'] !== 'pending');
?>
<section class="panel admin-section" id="ansoegninger">
    <h2>Hjælperansøgninger</h2>
    <?php if (!$pending): ?><p>Ingen ansøgninger afventer svar.</p><?php endif; ?>
    <?php foreach ($pending as $entry): ?><?php renderHelperApplication($adminLink, $entry); ?><?php endforeach; ?>
    <?php if ($decided): ?>
        <details class="nested-details applications-decided">
            <summary><strong>Afgjorte ansøgninger (<?= count($decided) ?>)</strong><?= icon('chevron', 16) ?></summary>
            <div class="nested-body">
                <?php foreach ($decided as $entry): ?><?php renderHelperApplication($adminLink, $entry); ?><?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
</section>
<?php

function renderHelperApplication(string $url, array $entry): void
{
    ?>
    <div class="helper-application">
        <div>
            <strong><?= e($entry['name']) ?></strong>
            <a class="text-link" href="mailto:<?= e($entry['email']) ?>"><?= e($entry['email']) ?></a>
        </div>
        <?php if ($entry['status'] === 'approved'): ?>
            <span class="status available">Godkendt</span>
        <?php else: ?>
            <form method="post" action="<?= e($url) ?>" class="admin-actions">
                <?php csrf('application', $entry['user_id']); ?>
                <button class="button primary small" name="decision" value="approved" type="submit">Godkend</button>
                <?php if ($entry['status'] === 'pending'): ?>
                    <button class="button secondary small" name="decision" value="rejected" type="submit">Afvis</button>
                <?php else: ?><span class="status">Afvist</span><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}
