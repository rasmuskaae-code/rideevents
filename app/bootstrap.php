<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Copenhagen');
umask(0077);

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = getenv('STAEVNE_DB') ?: dirname(__DIR__) . '/var/staevnevagt.sqlite';
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Kunne ikke oprette datamappen.');
    }

    $pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() === 0) {
        atomic(function () use ($pdo): void {
            if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() === 0) {
                $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
                $pdo->exec('PRAGMA user_version = 1');
            }
        });
    }
    return $pdo;
}

function query(string $sql, array $args = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($args);
    return $statement;
}

function row(string $sql, array $args = []): ?array
{
    return query($sql, $args)->fetch() ?: null;
}

function atomic(callable $work): mixed
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $work();
        $pdo->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        $pdo->exec('ROLLBACK');
        throw $error;
    }
}

function notify(int $user, string $title, string $body): void
{
    query('INSERT INTO notices (user_id,title,body,created_at) VALUES (?,?,?,?)', [$user,$title,$body,time()]);
}

function notifySignups(string $predicate, int $id, string $title, string $body): void
{
    $users = query("SELECT DISTINCT s.user_id FROM signups s JOIN shifts v ON v.id=s.shift_id JOIN groups g ON g.id=v.group_id WHERE $predicate = ?", [$id]);
    foreach ($users->fetchAll() as $user) {
        notify($user['user_id'], $title, $body);
    }
}

function reminders(?int $user = null): void
{
    $now = time();
    $sql = "INSERT OR IGNORE INTO notices (user_id,title,body,created_at,reminder_key)
        SELECT s.user_id, 'Din vagt starter snart', v.name || ' ved ' || e.name || ' starter om højst 30 minutter.', ?, s.id || ':' || v.starts_at
        FROM signups s JOIN shifts v ON v.id=s.shift_id JOIN groups g ON g.id=v.group_id JOIN events e ON e.id=g.event_id
        WHERE v.starts_at > ? AND v.starts_at <= ?";
    $args = [$now, $now, $now + 1800];
    if ($user !== null) {
        $sql .= ' AND s.user_id = ?';
        $args[] = $user;
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
