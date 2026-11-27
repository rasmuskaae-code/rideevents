<?php
declare(strict_types=1);

function uploadedImage(string $field, string $errorMessage): ?array
{
    $upload = $_FILES[$field] ?? [];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 3 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
        throw new InputError($errorMessage);
    }

    $info = @getimagesize($upload['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true)) {
        throw new InputError('Vælg et billede i JPG-, PNG- eller WebP-format.');
    }
    $bytes = file_get_contents($upload['tmp_name']);
    if ($bytes === false) {
        throw new InputError($errorMessage);
    }
    return [$info['mime'], $bytes];
}

function saveEventBanner(int $eventId): void
{
    $image = uploadedImage('banner', 'Upload et banner på højst 3 MB.');
    if ($image === null) {
        if (flag('remove_banner')) {
            query('DELETE FROM event_banners WHERE event_id=?', [$eventId]);
        }
        return;
    }
    query('DELETE FROM event_banners WHERE event_id=?', [$eventId]);
    $statement = db()->prepare('INSERT INTO event_banners (event_id,mime,data) VALUES (?,?,?)');
    $statement->bindValue(1, $eventId, PDO::PARAM_INT);
    $statement->bindValue(2, $image[0]);
    $statement->bindValue(3, $image[1], PDO::PARAM_LOB);
    $statement->execute();
}

function saveEventLink(int $eventId, int $id): void
{
    if ($id) {
        requireRow('SELECT id FROM event_links WHERE id=? AND event_id=?', [$id,$eventId]);
        if (flag('remove_link')) {
            query('DELETE FROM event_links WHERE id=? AND event_id=?', [$id,$eventId]);
            return;
        }
    }
    $label = field('label', 'Linktekst', 120);
    $url = field('url', 'Adresse', 2048);
    $parts = parse_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true)
        || isset($parts['user']) || isset($parts['pass'])) {
        throw new InputError('Indtast en gyldig adresse, der starter med https:// eller http://.');
    }
    if ($id) {
        query('UPDATE event_links SET label=?,url=? WHERE event_id=? AND id=?', [$label,$url,$eventId,$id]);
    } else {
        query('INSERT INTO event_links (label,url,event_id) VALUES (?,?,?)', [$label,$url,$eventId]);
    }
}
