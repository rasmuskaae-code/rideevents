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

        $rows = query('SELECT g.name AS group_name,g.visible AS group_visible,
            v.id AS shift_id,v.name AS shift_name,v.starts_at,v.ends_at,v.capacity,v.visible,v.bookable,
            u.name AS helper_name,u.email,
            (SELECT COUNT(*) FROM signups t WHERE t.shift_id=v.id) AS booked
            FROM `groups` g LEFT JOIN shifts v ON v.group_id=g.id
            LEFT JOIN signups s ON s.shift_id=v.id LEFT JOIN users u ON u.id=s.user_id
            WHERE g.event_id=? ORDER BY g.id,v.starts_at,v.id,LOWER(u.name),s.id', [$eventId])->fetchAll();

        $organizer = row('SELECT name FROM organizers WHERE id=?', [$event['organizer_id']]);
        return [$event, $organizer['name'], $rows];
    });
    if (!$report) {
        http_response_code(404);
        exit('Vi kunne ikke finde stævnet.');
    }
    session_write_close();

    [$event, $organizer, $rows] = $report;
    $columns = ['Vagtgruppe','Post','Start','Slut','Hjælper','E-mail','Tilmeldte','Pladser','Status'];
    $entries = [];
    $now = time();
    foreach ($rows as $row) {
        $status = match (true) {
            !$row['shift_id'] => 'Ingen poster',
            !$row['group_visible'] || !$row['visible'] => 'Skjult',
            $row['ends_at'] <= $now => 'Afholdt',
            $row['starts_at'] <= $now => 'I gang',
            !$row['bookable'] => 'Lukket',
            $row['booked'] >= $row['capacity'] => 'Fuldt booket',
            default => 'Åben',
        };
        $entries[] = [
            $row['group_name'], $row['shift_name'], $row['starts_at'], $row['ends_at'], $row['helper_name'], $row['email'],
            $row['shift_id'] ? $row['booked'] : null, $row['capacity'], $status,
        ];
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
