<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Copenhagen');
umask(0077);

require __DIR__ . '/database.php';

function notify(int $user, string $title, string $body): void
{
    query('INSERT INTO notices (user_id,title,body,created_at) VALUES (?,?,?,?)', [$user,$title,$body,time()]);
}

function notifySignups(string $predicate, int $id, string $title, string $body): void
{
    $users = query("SELECT DISTINCT s.user_id FROM signups s JOIN shifts v ON v.id=s.shift_id JOIN `groups` g ON g.id=v.group_id WHERE $predicate = ?", [$id]);
    foreach ($users->fetchAll() as $user) {
        notify($user['user_id'], $title, $body);
    }
}

function reminders(?int $user = null): void
{
    $now = time();
    $body = sqlConcat('v.name', "' ved '", 'e.name', "' starter om højst 30 minutter.'");
    $key = sqlConcat('s.id', "':'", 'v.starts_at');
    $insert = usingMysql() ? 'INSERT INTO' : 'INSERT OR IGNORE INTO';
    $sql = "$insert notices (user_id,title,body,created_at,reminder_key)
        SELECT s.user_id, 'Din vagt starter snart', $body, ?, $key
        FROM signups s JOIN shifts v ON v.id=s.shift_id JOIN `groups` g ON g.id=v.group_id JOIN events e ON e.id=g.event_id
        WHERE v.starts_at > ? AND v.starts_at <= ?";
    $args = [$now, $now, $now + 1800];
    if ($user !== null) {
        $sql .= ' AND s.user_id = ?';
        $args[] = $user;
    }
    if (usingMysql()) {
        $sql .= ' ON DUPLICATE KEY UPDATE reminder_key=notices.reminder_key';
    }
    query($sql, $args);
}

function dayLabel(string $date): string
{
    $months = [1=>'jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.'];
    $day = new DateTimeImmutable($date);
    return $day->format('j') . '. ' . $months[(int) $day->format('n')];
}

function eventDates(array $event): string
{
    $first = dayLabel($event['starts_on']);
    $ends = $event['ends_on'];
    if ($event['starts_on'] === $ends) {
        return $first . ' ' . substr($ends, 0, 4);
    }
    if (substr($event['starts_on'], 0, 7) === substr($ends, 0, 7)) {
        return (int) substr($event['starts_on'], 8) . '.–' . dayLabel($ends) . ' ' . substr($ends, 0, 4);
    }
    if (substr($event['starts_on'], 0, 4) !== substr($ends, 0, 4)) {
        $first .= ' ' . substr($event['starts_on'], 0, 4);
    }
    return $first . ' – ' . dayLabel($ends) . ' ' . substr($ends, 0, 4);
}

function disciplines(): array
{
    return ['dressur'=>'Dressur', 'spring'=>'Spring', 'begge'=>'Dressur og spring'];
}
