<?php
declare(strict_types=1);

function seedDemo(): void
{
    if (row('SELECT id FROM events LIMIT 1')) {
        return;
    }

    atomic(function (): void {
        if (row('SELECT id FROM events LIMIT 1')) {
            return;
        }
        $organizerId = (int) query('SELECT id FROM organizers WHERE owner_id IS NULL')->fetchColumn();
        $day = new DateTimeImmutable('first saturday of next month');
        $events = [
            ['Vinterstævne på Enggården', 'spring', 'Enggårdens Rideklub', $day, 'En weekend med springklasser. Hjælp med at gøre stævnet til en god oplevelse for ryttere, heste og publikum.'],
            ['Dressur på Enggården', 'dressur', 'Enggårdens Rideklub', $day->modify('+4 weeks'), 'En dag med dressurklasser for både ponyer og heste.'],
        ];
        foreach ($events as [$name, $discipline, $location, $start, $description]) {
            query('INSERT INTO events (name,discipline,location,starts_on,ends_on,description,visible,organizer_id) VALUES (?,?,?,?,?,?,1,?)',
                [$name,$discipline,$location,$start->format('Y-m-d'),$start->modify($discipline === 'spring' ? '+1 day' : '+0 days')->format('Y-m-d'),$description,$organizerId]);
            $eventId = (int) db()->lastInsertId();
            $groups = $discipline === 'spring'
                ? [
                    ['Bane & forhindringer', 'Hjælp med at gøre banen klar, sætte bomme op og bygge om mellem klasserne.', [
                        ['Banepersonale – formiddag', 8, 12, 5],
                        ['Banepersonale – eftermiddag', 12, 16, 4],
                        ['Banebygning', 16, 18, 3],
                    ]],
                    ['Sekretariat & indtjek', 'Tag imod rytterne, og hjælp med spørgsmål om startlister og resultater.', [
                        ['Indtjek og sekretariat', 8, 12, 2],
                        ['Resultater og præmier', 12, 16, 2],
                    ]],
                    ['Cafeteria', 'Hjælp med at servere mad og drikke til ryttere og besøgende.', [
                        ['Cafeteria – formiddag', 9, 12, 2],
                        ['Cafeteria – eftermiddag', 12, 16, 2],
                    ]],
                    ['Parkering & velkomst', 'Vis ryttere med trailer og besøgende, hvor de kan parkere.', [
                        ['Parkeringshjælp', 7, 10, 2],
                    ]],
                ]
                : [
                    ['Sekretariat & indtjek', 'Tag imod rytterne, og hjælp med startlister og resultater.', [['Skriver ved dommerbordet', 9, 12, 2]]],
                    ['Cafeteria', 'Servér kaffe og mad til gæster og ryttere.', [['Cafeteria – formiddag', 9, 12, 3]]],
                ];
            foreach ($groups as [$group, $job, $shifts]) {
                query('INSERT INTO groups (event_id,name,description,visible) VALUES (?,?,?,1)', [$eventId,$group,$job]);
                $groupId = (int) db()->lastInsertId();
                foreach ($shifts as [$shift, $from, $until, $capacity]) {
                    query('INSERT INTO shifts (group_id,name,starts_at,ends_at,capacity,visible,bookable) VALUES (?,?,?,?,?,1,1)',
                        [$groupId,$shift,$start->setTime($from, 0)->getTimestamp(),$start->setTime($until, 0)->getTimestamp(),$capacity]);
                }
            }
        }
    });
}
