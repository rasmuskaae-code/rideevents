<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function assetUrl(string $file): string
{
    $path = 'assets/' . $file;
    $version = hash_file('sha256', dirname(__DIR__) . '/public/' . $path);
    return $path . '?v=' . substr($version, 0, 16);
}

function icon(string $name, int $size = 20): string
{
    $paths = [
        'horse'=>'<path d="M7 21h13M8 21l1-4 4-5-3 1-3 3-4-2 2-5 4-3V3l4 2c3 0 5 2 6 5l1 11"/><path d="M8.5 9.5h.01M15 9c1 1 2 3 2 5"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'pin'=>'<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'chevron-right'=>'<path d="m9 6 6 6-6 6"/>',
        'logout'=>'<path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5m6-4 4-4-4-4m-7 4h11"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v4h16v-4"/>',
        'settings'=>'<path d="M4 7h16M4 17h16M9 4v6m6 4v6"/>',
        'chevron'=>'<path d="m6 9 6 6 6-6"/>',
        'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'user'=>'<circle cx="12" cy="8" r="3.5"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/>',
        'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3 4v2"/>',
        'flag'=>'<path d="M5 21V4m0 1c4-4 9 4 14 0v11c-5 4-10-4-14 0"/>',
        'clipboard'=>'<rect x="5" y="5" width="14" height="16" rx="2"/><rect x="9" y="3" width="6" height="4" rx="1" fill="var(--icon-bg,white)"/><path d="M9 11h6m-6 4h6"/>',
        'cup'=>'<path d="M3 8h13v5a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Zm13 0h2a3 3 0 0 1 0 6h-2M2 21h18M7 3v2m5-2v2"/>',
        'car'=>'<path d="m5 11 2-5h10l2 5M3 16v-4l2-1h14l2 1v4H3Zm2 0v3m14-3v3M7 14h.01M17 14h.01"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}

function csrf(string $action, ?int $id = null): void
{
    echo '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '"><input type="hidden" name="action" value="' . e($action) . '">';
    if ($id !== null) {
        echo '<input type="hidden" name="id" value="' . $id . '">';
    }
}

function groupIcon(string $name): string
{
    return match (true) {
        stripos($name, 'bane') !== false => 'flag',
        stripos($name, 'parkering') !== false => 'car',
        stripos($name, 'caf') !== false => 'cup',
        default => 'clipboard',
    };
}

function shiftDate(array $shift): string
{
    $start = new DateTimeImmutable('@' . $shift['starts_at']);
    $start = $start->setTimezone(new DateTimeZone('Europe/Copenhagen'));
    $end = $start->setTimestamp($shift['ends_at']);
    $days = ['søn.', 'man.', 'tir.', 'ons.', 'tor.', 'fre.', 'lør.'];
    $date = $days[(int) $start->format('w')] . ' ' . dayLabel($start->format('Y-m-d'));
    $endDay = $start->format('Y-m-d') === $end->format('Y-m-d') ? '' : dayLabel($end->format('Y-m-d')) . ' ';
    return $date . ', kl. ' . $start->format('H:i') . '–' . $endDay . $end->format('H:i');
}

function organizerFilterForm(array $organizers, int $selected, bool $admin): void
{
    ?>
    <form method="get" class="event-picker">
        <?php if ($admin): ?><input type="hidden" name="page" value="admin"><?php endif; ?>
        <label for="organizer-filter">Arrangør</label>
        <div class="select-wrap"><select name="organizer" id="organizer-filter" data-auto-submit>
            <option value="0">Alle arrangører</option>
            <?php foreach ($organizers as $option): ?>
                <option value="<?= $option['id'] ?>" <?= $selected === $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?><?= $option['active'] ? '' : ' (sat på pause)' ?></option>
            <?php endforeach; ?>
        </select><?= icon('chevron', 16) ?></div>
        <noscript><button class="button secondary small">Filtrér stævner</button></noscript>
    </form>
    <?php
}


function metricStrip(array $metrics, string $label): void
{
    ?>
    <dl class="panel metrics" aria-label="<?= e($label) ?>">
        <?php foreach ($metrics as [$glyph, $name, $value]): ?>
            <div class="metric">
                <span class="metric-icon"><?= icon($glyph, 20) ?></span>
                <div><dt><?= e($name) ?></dt><dd><?= e(number_format($value, 0, ',', '.')) ?></dd></div>
            </div>
        <?php endforeach; ?>
    </dl>
    <?php
}
