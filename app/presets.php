<?php
declare(strict_types=1);

function presetGroups(string $discipline): array
{
    $groups = [];
    if ($discipline !== 'dressur') {
        $groups['Bane & forhindringer'] = ['Hjælp med at sætte bomme op og bygge om mellem klasserne.', ['Banepersonale', 'Banebygning']];
    }
    if ($discipline !== 'spring') {
        $groups['Dommerbord'] = ['Hjælp dommeren med at skrive kommentarer og point ind under klassen.', ['Skriver ved dommerbordet']];
    }
    $groups['Sekretariat & indtjek'] = ['Tag imod rytterne, og hjælp med startlister og resultater.', ['Indtjek og sekretariat', 'Resultater og præmier']];
    $groups['Cafeteria'] = ['Hjælp med servering af mad og drikke.', ['Cafeteria']];
    $groups['Parkering & velkomst'] = ['Vis ryttere og gæster, hvor de kan parkere.', ['Parkeringshjælp']];
    return $groups;
}

function createPresetGroups(int $event, string $discipline, string $date): void
{
    $day = new DateTimeImmutable($date);
    foreach (presetGroups($discipline) as $group => [$description, $shifts]) {
        query('INSERT INTO groups (event_id,name,description,visible) VALUES (?,?,?,0)', [$event,$group,$description]);
        $groupId = (int) db()->lastInsertId();
        foreach ($shifts as $shift) {
            query('INSERT INTO shifts (group_id,name,starts_at,ends_at,capacity,visible,bookable) VALUES (?,?,?,?,1,1,0)',
                [$groupId,$shift,$day->setTime(9, 0)->getTimestamp(),$day->setTime(12, 0)->getTimestamp()]);
        }
    }
}
