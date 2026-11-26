<?php
declare(strict_types=1);

function notifyOrganizer(?int $owner, string $title, string $message): void
{
    $recipients = $owner === null
        ? query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN)
        : [$owner];
    foreach ($recipients as $recipient) {
        notify($recipient, $title, $message);
    }
}

function applyAsHelper(array $user, int $eventId): void
{
    if ($user['role'] !== 'helper') {
        throw new AccessDenied();
    }

    atomic(function () use ($user, $eventId): void {
        lockedRow('SELECT id FROM events WHERE id=?', [$eventId]);
        $event = lockedRow('SELECT e.name,o.owner_id FROM events e JOIN organizers o ON o.id=e.organizer_id
            WHERE e.id=? AND e.visible=1 AND o.active=1 AND e.ends_on>=?', [$eventId,date('Y-m-d')]);
        if (!$event) {
            throw new InputError('Dette stævne er lukket for ansøgninger.');
        }
        if (!insertUnique("INSERT INTO helper_applications (event_id,user_id,status,requested_at) VALUES (?,?,'pending',?)", [$eventId,$user['id'],time()])) {
            throw new InputError('Du har allerede ansøgt om at hjælpe ved dette stævne.');
        }
        notifyOrganizer($event['owner_id'], 'Ny hjælperansøgning', "{$user['name']} har ansøgt om at hjælpe ved {$event['name']}.");
    });
}

function decideHelperApplication(array $event, int $helper, string $decision): void
{
    if (!in_array($decision, ['approved','rejected'], true)) {
        throw new InputError('Vælg godkend eller afvis.');
    }

    $application = requireRow('SELECT status FROM helper_applications WHERE event_id=? AND user_id=?', [$event['id'],$helper]);
    if ($application['status'] === $decision) {
        return;
    }
    if ($application['status'] === 'approved') {
        throw new InputError('Hjælperen er allerede godkendt til stævnet.');
    }

    query('UPDATE helper_applications SET status=?,decided_at=? WHERE event_id=? AND user_id=?',
        [$decision,time(),$event['id'],$helper]);
    if ($decision === 'approved') {
        notify($helper, 'Ansøgning godkendt', "Du er godkendt som hjælper ved {$event['name']} og kan nu tilmelde dig vagter.");
    } else {
        notify($helper, 'Ansøgning afvist', "Din hjælperansøgning til {$event['name']} er afvist.");
    }
}
