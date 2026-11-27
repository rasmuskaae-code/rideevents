<?php
declare(strict_types=1);

function shiftStaffing(string $action, array $shift, array $bookings, array $contacts): void
{
    $assigned = [];
    foreach ($bookings as $booking) {
        if ($booking['external']) {
            $assigned[$booking['id']] = true;
        }
    }
    $available = array_filter($contacts, fn(array $contact): bool => !isset($assigned[$contact['id']]));
    ?>
    <div class="shift-staffing">
        <h4>Hjælpere på vagten</h4>
        <?php if (!$bookings): ?><p>Ingen hjælpere på vagten endnu.</p><?php endif; ?>
        <?php foreach ($bookings as $booking): ?>
            <div class="signup-row">
                <div><strong><?= e($booking['name']) ?></strong><?php if ($booking['external']): ?> <span class="status">Ekstern</span><?php endif; ?><br>
                    <?php if ($booking['external']): ?><span class="muted"><?= e($booking['contact']) ?></span>
                    <?php else: ?><a class="text-link" href="mailto:<?= e($booking['contact']) ?>"><?= e($booking['contact']) ?></a><?php endif; ?>
                </div>
                <?php if ($booking['external']): ?>
                    <form method="post" action="<?= e($action) ?>">
                        <?php csrf('external-assignment', $shift['id']); ?>
                        <input type="hidden" name="contact_id" value="<?= $booking['id'] ?>">
                        <button class="button danger small" name="remove_assignment" value="1" type="submit">Fjern fra vagten</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= e($action) ?>" data-confirm="Afmeld hjælperen fra denne vagt?">
                        <?php csrf('cancel', $booking['id']); ?>
                        <button class="button danger small" type="submit">Afmeld</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($available && count($bookings) < $shift['capacity']): ?>
            <form method="post" action="<?= e($action) ?>" class="form-stack external-assignment">
                <?php csrf('external-assignment', $shift['id']); ?>
                <label>Ekstern hjælper<span class="select-wrap"><select name="contact_id" required>
                    <option value="">Vælg ekstern hjælper</option>
                    <?php foreach ($available as $contact): ?><option value="<?= $contact['id'] ?>"><?= e($contact['name']) ?></option><?php endforeach; ?>
                </select><?= icon('chevron', 16) ?></span></label>
                <div><button class="button primary small" type="submit">Tilknyt vagten</button></div>
            </form>
        <?php elseif (!$contacts && count($bookings) < $shift['capacity']): ?>
            <p class="external-assignment"><a class="text-link" href="#kontakter">Opret en ekstern hjælper</a> for at sætte personen på vagten.</p>
        <?php endif; ?>
    </div>
    <?php
}

function groupLeadForm(string $action, int $group, ?array $lead, array $approved, array $contacts): void
{
    $selected = $lead ? ($lead['user_id'] !== null ? 'user:' . $lead['user_id'] : 'contact:' . $lead['contact_id']) : '';
    ?>
    <form method="post" action="<?= e($action) ?>" class="form-stack">
        <?php csrf('group-lead', $group); ?>
        <label>Ansvarlig<span class="select-wrap"><select name="responsible">
            <option value="">Ingen ansvarlig</option>
            <?php if ($approved): ?><optgroup label="Godkendte hjælpere">
                <?php foreach ($approved as $helper): ?><option value="user:<?= $helper['user_id'] ?>" <?= $selected === 'user:' . $helper['user_id'] ? 'selected' : '' ?>><?= e($helper['name']) ?> · <?= e($helper['email']) ?></option><?php endforeach; ?>
            </optgroup><?php endif; ?>
            <?php if ($contacts): ?><optgroup label="Eksterne hjælpere">
                <?php foreach ($contacts as $contact): ?><option value="contact:<?= $contact['id'] ?>" <?= $selected === 'contact:' . $contact['id'] ? 'selected' : '' ?>><?= e($contact['name']) ?></option><?php endforeach; ?>
            </optgroup><?php endif; ?>
        </select><?= icon('chevron', 16) ?></span></label>
        <div><button class="button primary small" type="submit">Gem ansvarlig</button></div>
    </form>
    <?php
}
