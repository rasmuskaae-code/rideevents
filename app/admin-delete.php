<?php
declare(strict_types=1);

function deletionTarget(array $event, string $kind, int $id): ?array
{
    return match ($kind) {
        'event' => $id === $event['id'] ? $event : null,
        'group' => row('SELECT * FROM `groups` WHERE event_id=? AND id=?', [$event['id'],$id]),
        'shift' => row('SELECT v.* FROM shifts v JOIN `groups` g ON g.id=v.group_id WHERE g.event_id=? AND v.id=?', [$event['id'],$id]),
        default => null,
    };
}

function deletionConfirmation(array $event, array $target, string $kind): string
{
    return hash_hmac('sha256', serialize([$event['id'],$event['organizer_id'],$kind,$target]), $_SESSION['csrf']);
}

function deleteAdminItem(array $event, string $kind, int $id): int
{
    $target = deletionTarget($event, $kind, $id);
    if (!$target || !flag('confirm_delete') || !hash_equals(deletionConfirmation($event, $target, $kind), input('confirmation'))) {
        throw new InputError('Elementet er ændret, eller bekræftelse mangler. Åbn sletningen igen.');
    }

    $name = $target['name'];
    $eventName = $event['name'];
    if ($kind === 'shift') {
        notifySignups('v.id', $id, 'Din vagt er slettet', "Vagten $name ved $eventName er slettet.");
        query('DELETE FROM signups WHERE shift_id=?', [$id]);
        query('DELETE FROM shifts WHERE id=?', [$id]);
        return $event['id'];
    }

    $filter = $kind === 'group' ? 'id' : 'event_id';
    $message = $kind === 'group' ? "Vagtgruppen $name ved $eventName er slettet." : "Stævnet $eventName er slettet.";
    notifySignups("g.$filter", $id, 'Din vagt er slettet', $message);
    $groups = "SELECT id FROM `groups` WHERE $filter=?";
    query("DELETE FROM signups WHERE shift_id IN (SELECT id FROM shifts WHERE group_id IN ($groups))", [$id]);
    query("DELETE FROM shifts WHERE group_id IN ($groups)", [$id]);
    query("DELETE FROM images WHERE group_id IN ($groups)", [$id]);
    query("DELETE FROM `groups` WHERE $filter=?", [$id]);

    if ($kind === 'event') {
        query('DELETE FROM contacts WHERE event_id=?', [$id]);
        query('DELETE FROM events WHERE id=?', [$id]);
        return 0;
    }
    return $event['id'];
}
