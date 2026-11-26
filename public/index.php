<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/actions.php';
require dirname(__DIR__) . '/app/organizers.php';
require dirname(__DIR__) . '/app/ui.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
header('Cache-Control: private, no-store');
session_start([
    'use_strict_mode' => 1,
    'cookie_httponly' => true,
    'cookie_secure' => getenv('STAEVNE_SECURE_COOKIE') === '1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'cookie_samesite' => 'Lax',
]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$user = isset($_SESSION['user']) ? account($_SESSION['user']) : null;
$isAdmin = isPlatformAdmin($user);
$canManage = canManageEvents($user);
$canCreateOrganizer = !$isAdmin && empty($user['organizer_id']);
$demo = getenv('STAEVNE_DEMO') === '1';
if ($demo) {
    require dirname(__DIR__) . '/app/demo.php';
    seedDemo();
}

$queryId = (int) ($_GET['event'] ?? 0);
$page = $_GET['page'] ?? 'events';
$page = in_array($page, ['events','admin','platform','login','register','organizer','mine','notices','image'], true) ? $page : 'events';

if ($page === 'image') {
    $userId = $user['id'] ?? 0;
    $image = row("SELECT i.* FROM images i JOIN groups g ON g.id=i.group_id JOIN events e ON e.id=g.event_id
        JOIN organizers o ON o.id=e.organizer_id
        WHERE i.id=? AND ((o.active=1 AND (o.owner_id=? OR (g.visible=1 AND e.visible=1)))
        OR EXISTS (SELECT 1 FROM users WHERE id=? AND role='admin')
        OR EXISTS (SELECT 1 FROM signups s JOIN shifts v ON v.id=s.shift_id WHERE s.user_id=? AND v.group_id=g.id))",
        [(int) ($_GET['id'] ?? 0), $userId, $userId, $userId]);
    if (!$image) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $image['mime']);
    echo $image['data'];
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost($user, $queryId, $page);
}
if (($page === 'admin' && !$canManage) || ($page === 'platform' && !$isAdmin)) {
    http_response_code(403);
    $page = 'forbidden';
}
if ($user && in_array($page, ['login','register'], true)) {
    go('?event=' . $queryId);
}
if ($page === 'organizer' && !$canCreateOrganizer) {
    if ($canManage) {
        go($isAdmin ? '?page=platform' : '?page=admin');
    }
    http_response_code(403);
    $page = 'forbidden';
}
if (in_array($page, ['mine','notices'], true) && !$user) {
    go('?page=login&event=' . $queryId);
}
if ($user) {
    reminders($user['id']);
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$eventSql = 'SELECT e.*,o.name AS organizer_name,o.active AS organizer_active
    FROM events e JOIN organizers o ON o.id=e.organizer_id';
$args = [];
$allowOrganizerFilter = $page === 'events' || ($page === 'admin' && $isAdmin);
$organizerOptions = match (true) {
    $page === 'admin' && $isAdmin => query('SELECT id,name,active,owner_id FROM organizers ORDER BY name COLLATE NOCASE,id')->fetchAll(),
    $page === 'events' => query('SELECT DISTINCT o.id,o.name,o.active FROM organizers o JOIN events e ON e.organizer_id=o.id
        WHERE o.active=1 AND e.visible=1 ORDER BY o.name COLLATE NOCASE,o.id')->fetchAll(),
    default => [],
};
$organizerFilter = (int) ($_GET['organizer'] ?? 0);
if (!$allowOrganizerFilter || !in_array($organizerFilter, array_column($organizerOptions, 'id'), true)) {
    $organizerFilter = 0;
}

if ($page === 'admin') {
    if (!$isAdmin) {
        $eventSql .= ' WHERE o.owner_id=? AND o.active=1';
        $args[] = $user['id'];
    }
} else {
    $eventSql .= ' WHERE e.visible=1 AND o.active=1';
}
$events = query($eventSql . ' ORDER BY e.starts_on,e.id', $args)->fetchAll();
$eventsById = array_column($events, null, 'id');
$today = date('Y-m-d');
$event = $eventsById[$queryId] ?? null;
if ($page === 'admin' && $queryId > 0 && !$event) {
    http_response_code(404);
    $page = 'forbidden';
}
if ($organizerFilter) {
    $events = array_values(array_filter($events, fn(array $candidate): bool => $candidate['organizer_id'] === $organizerFilter));
    if ($event && $event['organizer_id'] !== $organizerFilter) {
        $event = null;
    }
}
if (!$event && $page !== 'forbidden' && !($page === 'admin' && isset($_GET['event']) && $queryId === 0)) {
    $upcoming = array_filter($events, fn(array $candidate): bool => $candidate['ends_on'] >= $today);
    $event = reset($upcoming) ?: reset($events) ?: null;
}
$eventId = $event['id'] ?? 0;
$eventLink = '?event=' . $eventId;
$unread = $user ? (int) query('SELECT COUNT(*) FROM notices WHERE user_id=? AND read_at IS NULL', [$user['id']])->fetchColumn() : 0;
$title = match ($page) {
    'admin' => 'Stævneadmin',
    'platform' => 'Platformadmin',
    'organizer' => 'Opret arrangør',
    'login' => 'Log ind',
    'register' => 'Opret bruger',
    'mine' => 'Mine vagter',
    'notices' => 'Notifikationer',
    default => 'Stævner',
};
?>
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#173a2a">
    <title><?= e($title) ?> · Stævnevagt</title>
    <link rel="stylesheet" href="assets/styles.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
<a class="skip" href="#indhold">Gå til indhold</a>
<header class="site-header">
    <div class="shell header-inner">
        <a class="brand" href="<?= e($eventLink) ?>"><span class="brand-mark"><?= icon('horse', 25) ?></span><span>Stævnevagt</span></a>
        <nav aria-label="Hovedmenu" class="header-nav">
            <a href="<?= e($eventLink) ?>" <?= $page === 'events' ? 'aria-current="page"' : '' ?>>Stævner</a>
            <?php if (!$isAdmin): ?><a href="?page=mine&amp;event=<?= $eventId ?>" <?= $page === 'mine' ? 'aria-current="page"' : '' ?>>Mine vagter</a><?php endif; ?>
            <?php if ($canManage): ?><a href="?page=admin" <?= $page === 'admin' ? 'aria-current="page"' : '' ?>>Stævneadmin</a><?php endif; ?>
            <?php if ($isAdmin): ?><a href="?page=platform" <?= $page === 'platform' ? 'aria-current="page"' : '' ?>>Platformadmin</a><?php endif; ?>
            <?php if ($canCreateOrganizer): ?><a href="?page=organizer" <?= $page === 'organizer' ? 'aria-current="page"' : '' ?>>Opret arrangør</a><?php endif; ?>
        </nav>
        <div class="account">
            <?php if ($user): ?>
                <a class="bell-button" href="?page=notices&amp;event=<?= $eventId ?>" aria-label="Notifikationer<?= $unread ? ', ' . $unread . ' ulæste' : '' ?>"><?= icon('bell') ?><?php if ($unread): ?><span class="unread-dot"></span><?php endif; ?></a>
                <form method="post" action="<?= e($eventLink) ?>"><?php csrf('logout'); ?><button class="button secondary small" type="submit">Log ud</button></form>
            <?php else: ?>
                <a class="button secondary small" href="?page=login"><?= icon('user', 17) ?> Log ind</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<main id="indhold" class="shell">
<?php if ($flash): ?><div class="flash <?= e($flash[0]) ?>" role="status"><?= icon($flash[0] === 'ok' ? 'check' : 'info') ?><?= e($flash[1]) ?></div><?php endif; ?>
<?php
if ($page === 'events') {
    require dirname(__DIR__) . '/app/events-view.php';
} elseif ($page === 'admin') {
    require dirname(__DIR__) . '/app/admin-view.php';
} elseif ($page === 'platform') {
    require dirname(__DIR__) . '/app/platform-view.php';
} else {
    require dirname(__DIR__) . '/app/account-view.php';
}
?>
</main>
<footer class="site-footer">
    <div class="shell footer-inner"><a class="brand footer-brand" href="<?= e($eventLink) ?>"><?= icon('horse', 19) ?> Stævnevagt</a><span><?= $demo ? 'Demovisning med eksempeldata' : 'Hjælperkoordinering til ridestævner' ?></span></div>
</footer>
</body>
</html>
