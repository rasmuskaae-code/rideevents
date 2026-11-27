<?php
declare(strict_types=1);

function exportHelpers(?array $user, int $eventId, mixed $format): never
{
    if (!canManageEvents($user)) {
        http_response_code(403);
        exit('Du har ikke adgang til denne eksport.');
    }
    if (!in_array($format, ['pdf','xlsx'], true)) {
        http_response_code(404);
        exit('Vælg PDF eller Excel.');
    }

    $report = atomic(function () use ($user, $eventId): ?array {
        $event = managedEvent($eventId, $user, true);
        if (!$event) {
            return null;
        }

        $rows = query('SELECT g.id AS group_id,g.name AS group_name,g.visible AS group_visible,
            v.id AS shift_id,v.name AS shift_name,v.starts_at,v.ends_at,v.capacity,v.visible,v.bookable
            FROM `groups` g LEFT JOIN shifts v ON v.group_id=g.id
            WHERE g.event_id=? ORDER BY g.id,v.starts_at,v.id', [$eventId])->fetchAll();

        $organizer = row('SELECT name FROM organizers WHERE id=?', [$event['organizer_id']]);
        return [$event, $organizer['name'], $rows, eventRoster($eventId), groupLeads($eventId)];
    });
    if (!$report) {
        http_response_code(404);
        exit('Vi kunne ikke finde stævnet.');
    }
    session_write_close();

    [$event, $organizer, $rows, $roster, $leads] = $report;
    $columns = ['Vagtgruppe','Post','Start','Slut','Hjælper','Kontakt','Tilmeldte','Pladser','Status','Ansvarlig'];
    $entries = [];
    $now = time();
    foreach ($rows as $row) {
        $bookings = $roster[$row['shift_id']] ?? [];
        usort($bookings, fn(array $first, array $second): int => strcasecmp($first['name'], $second['name']));
        $booked = count($bookings);
        $status = match (true) {
            !$row['shift_id'] => 'Ingen poster',
            !$row['group_visible'] || !$row['visible'] => 'Skjult',
            $row['ends_at'] <= $now => 'Afholdt',
            $row['starts_at'] <= $now => 'I gang',
            !$row['bookable'] => 'Lukket',
            $booked >= $row['capacity'] => 'Fuldt booket',
            default => 'Åben',
        };
        $lead = $leads[$row['group_id']] ?? null;
        $leadName = $lead ? $lead['name'] . ($lead['contact_id'] !== null ? ' (ekstern)' : '') : null;
        foreach ($bookings ?: [null] as $booking) {
            $name = $booking ? $booking['name'] . ($booking['external'] ? ' (ekstern)' : '') : null;
            $entries[] = [
                $row['group_name'], $row['shift_name'], $row['starts_at'], $row['ends_at'], $name, $booking['contact'] ?? null,
                $row['shift_id'] ? $booked : null, $row['capacity'], $status, $leadName,
            ];
        }
    }
    $meta = $organizer . ' · ' . $event['location'] . ' · ' . eventDates($event) . ' · Dansk tid';

    if ($format === 'pdf') {
        if (!extension_loaded('mbstring') || !extension_loaded('zlib')) {
            http_response_code(501);
            exit('Aktivér mbstring og zlib i PHP for at hente PDF.');
        }
        require __DIR__ . '/report-pdf.php';
        $contents = helperReportPdf($event['name'], $meta, $columns, $entries);
        $type = 'application/pdf';
    } else {
        if (!class_exists(ZipArchive::class)) {
            http_response_code(501);
            exit('Aktivér zip i PHP for at hente Excel.');
        }
        require __DIR__ . '/report-excel.php';
        $contents = helperReportExcel($event['name'], $meta, $columns, $entries);
        $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    $slug = trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', $event['name']), '-');
    $file = rawurlencode('Hjælperoversigt-' . ($slug ?: $eventId) . '.' . $format);
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="hjaelperoversigt-' . $eventId . '.' . $format . '"; filename*=UTF-8\'\'' . $file);
    header('Content-Length: ' . strlen($contents));
    echo $contents;
    exit;
}
