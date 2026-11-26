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

function createOrganizer(int $owner, string $name): void
{
    $created = query('INSERT OR IGNORE INTO organizers (owner_id,name) VALUES (?,?)', [$owner,$name])->rowCount();
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
