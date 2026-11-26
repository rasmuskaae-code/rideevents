<?php
declare(strict_types=1);

function saveAdmin(string $action, int $eventId, array $user): int
{
    $id = (int) input('id');
    $isAdmin = isPlatformAdmin($user);
    $creatingEvent = $action === 'event' && $id === 0 && $eventId === 0;
    $event = $creatingEvent ? null : managedEvent($eventId, $user, true);
    if (!$creatingEvent && !$event) {
        throw new AccessDenied();
    }
    if ($action === 'delete') {
        require_once __DIR__ . '/admin-delete.php';
        return deleteAdminItem($event, input('kind'), $id);
    }
    if ($action === 'event') {
        if (!$creatingEvent && $id !== $eventId) {
            throw new AccessDenied();
        }

        if ($isAdmin) {
            $organizer = input('organizer_id') === ''
                ? ($event ? ['id'=>$event['organizer_id']] : requireRow('SELECT id FROM organizers WHERE owner_id IS NULL', []))
                : requireRow('SELECT id FROM organizers WHERE id=?', [(int) input('organizer_id')]);
        } else {
            $organizer = $event
                ? ['id'=>$event['organizer_id']]
                : (lockedRow('SELECT id FROM organizers WHERE owner_id=? AND active=1', [$user['id']]) ?? throw new AccessDenied());
        }
        $starts = parseDate(input('starts_on'), 'Y-m-d')->format('Y-m-d');
        $ends = parseDate(input('ends_on'), 'Y-m-d')->format('Y-m-d');
        $discipline = input('discipline');
        if ($starts > $ends || !isset(disciplines()[$discipline])) {
            throw new InputError('Tjek datoer og disciplin.');
        }
        $values = [field('name', 'Stævnenavn', 160), $discipline, field('location', 'Sted', 200), $starts, $ends,
            field('description', 'Beskrivelse', 2500, false), flag('visible'), $organizer['id']];
        if ($creatingEvent) {
            query('INSERT INTO events (name,discipline,location,starts_on,ends_on,description,visible,organizer_id) VALUES (?,?,?,?,?,?,?,?)', $values);
            $eventId = (int) db()->lastInsertId();
            if (flag('presets')) {
                require_once __DIR__ . '/presets.php';
                createPresetGroups($eventId, $discipline, $starts);
            }
            return $eventId;
        }
        foreach (query('SELECT v.starts_at,v.ends_at FROM shifts v JOIN `groups` g ON g.id=v.group_id WHERE g.event_id=?', [$eventId]) as $shift) {
            if (date('Y-m-d', $shift['starts_at']) < $starts || $shift['ends_at'] > parseDate($ends, 'Y-m-d')->modify('+1 day')->getTimestamp()) {
                throw new InputError('Stævnet skal dække de oprettede vagter. Tilpas vagternes tider, før du forkorter perioden.');
            }
        }
        query('UPDATE events SET name=?,discipline=?,location=?,starts_on=?,ends_on=?,description=?,visible=?,organizer_id=? WHERE id=?', [...$values,$eventId]);
        if ($event['organizer_id'] !== $organizer['id'] || array_values(array_intersect_key($event, array_flip(['name','discipline','location','starts_on','ends_on','description']))) !== array_slice($values, 0, 6)) {
            notifySignups('g.event_id', $eventId, 'Stævnet er opdateret', "Der er nye oplysninger om {$values[0]}. Se din vagt og stævneoplysningerne i Stævnevagt.");
        }
        return $eventId;
    }

    if ($action === 'group') {
        $values = [field('name', 'Gruppenavn', 160), field('description', 'Jobbeskrivelse', 3000, false), flag('visible')];
        if ($id) {
            $previous = requireRow('SELECT * FROM `groups` WHERE id=? AND event_id=?', [$id,$eventId]);
            query('UPDATE `groups` SET name=?,description=?,visible=? WHERE id=?', [...$values,$id]);
        } else {
            query('INSERT INTO `groups` (name,description,visible,event_id) VALUES (?,?,?,?)', [...$values,$eventId]);
            $id = (int) db()->lastInsertId();
        }
        $changedImages = saveGroupImages($id);
        if (isset($previous) && ($changedImages || $previous['name'] !== $values[0] || $previous['description'] !== $values[1])) {
            notifySignups('g.id', $id, 'Jobbeskrivelsen er opdateret', "Se de nye oplysninger om {$values[0]} ved {$event['name']} i Stævnevagt.");
        }
    } elseif ($action === 'shift') {
        $group = requireRow('SELECT id FROM `groups` WHERE id=? AND event_id=?', [(int) input('group_id'),$eventId]);
        $from = parseDate(input('starts_at'), 'Y-m-d\TH:i');
        $until = parseDate(input('ends_at'), 'Y-m-d\TH:i');
        if ($from >= $until || $from->format('Y-m-d') < $event['starts_on'] || $until > parseDate($event['ends_on'], 'Y-m-d')->modify('+1 day')) {
            throw new InputError('Vælg et start- og sluttidspunkt inden for stævnets datoer.');
        }
        $capacity = filter_var(input('capacity'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>10000]]);
        if (!$capacity) {
            throw new InputError('Vælg et antal pladser mellem 1 og 10.000.');
        }
        $values = [field('name', 'Postens navn', 160), $from->getTimestamp(), $until->getTimestamp(), $capacity, flag('visible'), flag('bookable')];
        if ($id) {
            $previous = requireRow('SELECT * FROM shifts WHERE id=? AND group_id=?', [$id,$group['id']]);
            if ($capacity < query('SELECT COUNT(*) FROM signups WHERE shift_id=?', [$id])->fetchColumn()) {
                throw new InputError('Der er flere tilmeldte end det valgte antal pladser.');
            }
            query('UPDATE shifts SET name=?,starts_at=?,ends_at=?,capacity=?,visible=?,bookable=? WHERE id=?', [...$values,$id]);
            if ([$previous['name'], $previous['starts_at'], $previous['ends_at']] !== array_slice($values, 0, 3)) {
                $time = dayLabel($from->format('Y-m-d')) . ' kl. ' . $from->format('H:i') . ' – ' . dayLabel($until->format('Y-m-d')) . ' kl. ' . $until->format('H:i');
                notifySignups('v.id', $id, 'Din vagt er ændret', "Din vagt ved {$event['name']} er nu {$values[0]}, $time.");
            }
        } else {
            query('INSERT INTO shifts (name,starts_at,ends_at,capacity,visible,bookable,group_id) VALUES (?,?,?,?,?,?,?)', [...$values,$group['id']]);
        }
    } elseif ($action === 'contact') {
        $values = [field('name', 'Navn', 120), field('contact', 'Kontakt', 254), field('task', 'Opgave', 500)];
        if ($id) {
            requireRow('SELECT id FROM contacts WHERE id=? AND event_id=?', [$id,$eventId]);
            query('UPDATE contacts SET name=?,contact=?,task=? WHERE id=? AND event_id=?', [...$values,$id,$eventId]);
        } else {
            query('INSERT INTO contacts (name,contact,task,event_id) VALUES (?,?,?,?)', [...$values,$eventId]);
        }
    } elseif ($action === 'cancel') {
        $signup = requireRow('SELECT s.user_id,v.name FROM signups s JOIN shifts v ON v.id=s.shift_id
            JOIN `groups` g ON g.id=v.group_id WHERE s.id=? AND g.event_id=?', [$id,$eventId]);
        query('DELETE FROM signups WHERE id=?', [$id]);
        notify($signup['user_id'], 'Du er afmeldt en vagt', "Du er afmeldt {$signup['name']} ved {$event['name']}.");
    } else {
        throw new InputError('Ukendt handling.');
    }
    return $eventId;
}

function saveGroupImages(int $group): bool
{
    $changed = false;
    for ($position = 1; $position <= 2; $position++) {
        $upload = $_FILES['image' . $position] ?? [];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if (flag('remove_image' . $position)) {
                $changed = query('DELETE FROM images WHERE group_id=? AND position=?', [$group,$position])->rowCount() > 0 || $changed;
            }
            continue;
        }
        if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 3 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
            throw new InputError('Upload et billede på højst 3 MB.');
        }
        $info = getimagesize($upload['tmp_name']);
        if (!$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true)) {
            throw new InputError('Vælg et billede i JPG-, PNG- eller WebP-format.');
        }
        $existing = row('SELECT id FROM images WHERE group_id=? AND position=?', [$group,$position]);
        $statement = db()->prepare($existing
            ? 'UPDATE images SET mime=?,data=? WHERE group_id=? AND position=?'
            : 'INSERT INTO images (mime,data,group_id,position) VALUES (?,?,?,?)');
        $statement->bindValue(1, $info['mime']);
        $statement->bindValue(2, file_get_contents($upload['tmp_name']), PDO::PARAM_LOB);
        $statement->bindValue(3, $group, PDO::PARAM_INT);
        $statement->bindValue(4, $position, PDO::PARAM_INT);
        $statement->execute();
        $changed = true;
    }
    return $changed;
}
