<?php
declare(strict_types=1);

function occupiedSeats(int $shift): int
{
    return (int) query('SELECT (SELECT COUNT(*) FROM signups WHERE shift_id=?) +
        (SELECT COUNT(*) FROM external_shift_assignments WHERE shift_id=?)', [$shift,$shift])->fetchColumn();
}

function eventRoster(int $eventId): array
{
    $rows = query('SELECT v.id AS shift_id,s.id AS id,u.name,u.email AS contact,0 AS external,s.created_at AS sort_at
        FROM signups s JOIN users u ON u.id=s.user_id JOIN shifts v ON v.id=s.shift_id
        JOIN `groups` g ON g.id=v.group_id WHERE g.event_id=?
        UNION ALL
        SELECT v.id AS shift_id,c.id,c.name,c.contact,1 AS external,0 AS sort_at
        FROM external_shift_assignments a JOIN contacts c ON c.id=a.contact_id JOIN shifts v ON v.id=a.shift_id
        JOIN `groups` g ON g.id=v.group_id WHERE g.event_id=?
        ORDER BY shift_id,external,sort_at,id', [$eventId,$eventId])->fetchAll();

    $byShift = [];
    foreach ($rows as $row) {
        $byShift[$row['shift_id']][] = $row;
    }
    return $byShift;
}

function groupLeads(int $eventId): array
{
    return array_column(query('SELECT l.*,COALESCE(u.name,c.name) AS name FROM group_leads l
        JOIN `groups` g ON g.id=l.group_id LEFT JOIN users u ON u.id=l.user_id LEFT JOIN contacts c ON c.id=l.contact_id
        WHERE g.event_id=?', [$eventId])->fetchAll(), null, 'group_id');
}

function assignExternalHelper(int $eventId, int $shiftId, int $contactId, bool $remove): void
{
    $shift = requireRow('SELECT v.capacity FROM shifts v JOIN `groups` g ON g.id=v.group_id
        WHERE v.id=? AND g.event_id=?', [$shiftId,$eventId]);
    requireRow('SELECT id FROM contacts WHERE id=? AND event_id=?', [$contactId,$eventId]);

    if ($remove) {
        query('DELETE FROM external_shift_assignments WHERE shift_id=? AND contact_id=?', [$shiftId,$contactId]);
        return;
    }
    if (row('SELECT contact_id FROM external_shift_assignments WHERE shift_id=? AND contact_id=?', [$shiftId,$contactId])) {
        throw new InputError('Den eksterne hjælper er allerede sat på vagten.');
    }
    if (occupiedSeats($shiftId) >= $shift['capacity']) {
        throw new InputError('Alle pladser er taget.');
    }
    query('INSERT INTO external_shift_assignments (shift_id,contact_id) VALUES (?,?)', [$shiftId,$contactId]);
}

function saveGroupLead(array $event, int $groupId, string $choice): void
{
    $group = requireRow('SELECT name FROM `groups` WHERE id=? AND event_id=?', [$groupId,$event['id']]);
    $old = row('SELECT user_id,contact_id FROM group_leads WHERE group_id=?', [$groupId]);
    $user = $contact = null;
    if ($choice !== '') {
        if (!preg_match('/^(user|contact):([1-9][0-9]*)$/D', $choice, $parts)) {
            throw new InputError('Vælg en hjælper til gruppen.');
        }
        $id = (int) $parts[2];
        if ($parts[1] === 'user') {
            requireRow("SELECT user_id FROM helper_applications WHERE event_id=? AND user_id=? AND status='approved'", [$event['id'],$id]);
            $user = $id;
        } else {
            requireRow('SELECT id FROM contacts WHERE event_id=? AND id=?', [$event['id'],$id]);
            $contact = $id;
        }
    }
    if (($old['user_id'] ?? null) === $user && ($old['contact_id'] ?? null) === $contact) {
        return;
    }

    query('DELETE FROM group_leads WHERE group_id=?', [$groupId]);
    if ($user !== null || $contact !== null) {
        query('INSERT INTO group_leads (group_id,user_id,contact_id) VALUES (?,?,?)', [$groupId,$user,$contact]);
    }
    if (!empty($old['user_id'])) {
        notify($old['user_id'], 'Gruppeansvar ændret', "Du er ikke længere ansvarlig for {$group['name']} ved {$event['name']}.");
    }
    if ($user !== null) {
        notify($user, 'Du er ansvarlig for en vagtgruppe', "Du er udnævnt som ansvarlig for {$group['name']} ved {$event['name']}.");
    }
}
