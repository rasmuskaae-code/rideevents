<?php
declare(strict_types=1);

function account(int $id): ?array
{
    return row('SELECT u.id,u.name,u.email,u.role,o.id AS organizer_id,o.name AS organizer_name,o.active AS organizer_active
        FROM users u LEFT JOIN organizers o ON o.owner_id=u.id WHERE u.id=?', [$id]);
}

function isPlatformAdmin(?array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

function canManageEvents(?array $user): bool
{
    return isPlatformAdmin($user) || ($user['organizer_active'] ?? 0) === 1;
}

function managedEvent(int $id, array $user, bool $lock = false): ?array
{
    $fetch = $lock ? 'lockedRow' : 'row';
    $event = $fetch('SELECT * FROM events WHERE id=?', [$id]);
    if (!$event || isPlatformAdmin($user)) {
        return $event;
    }
    $organizer = $fetch('SELECT id FROM organizers WHERE id=? AND owner_id=? AND active=1', [$event['organizer_id'],$user['id']]);
    return $organizer ? $event : null;
}

function createOrganizer(int $owner, string $name): void
{
    $created = insertUnique('INSERT INTO organizers (owner_id,name) VALUES (?,?)', [$owner,$name]);
    if (!$created) {
        throw new InputError('Din profil er allerede knyttet til en arrangør.');
    }
}

function saveOrganizer(): void
{
    $organizer = requireRow('SELECT id,owner_id FROM organizers WHERE id=?', [(int) input('id')]);
    $active = $organizer['owner_id'] === null ? 1 : flag('active');
    query('UPDATE organizers SET name=?,active=? WHERE id=?', [field('organizer_name', 'Arrangørens navn', 160),$active,$organizer['id']]);
}
