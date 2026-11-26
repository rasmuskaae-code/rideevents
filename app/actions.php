<?php
declare(strict_types=1);

final class InputError extends RuntimeException {}
final class AccessDenied extends RuntimeException {}

function input(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function field(string $key, string $label, int $limit, bool $required = true): string
{
    $value = trim(input($key));
    if (($required && $value === '') || strlen($value) > $limit || !preg_match('//u', $value)) {
        throw new InputError("Tjek feltet «$label».");
    }
    return $value;
}

function flag(string $field): int
{
    return (int) (input($field) === '1');
}

function parseDate(string $value, string $format): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
    if (!$date || $date->format($format) !== $value) {
        throw new InputError('Indtast en gyldig dato og tid.');
    }
    return $date;
}

function requireRow(string $sql, array $args): array
{
    return row($sql, $args) ?? throw new InputError('Vi kunne ikke finde det valgte element.');
}

function go(string $url, string $message = ''): never
{
    if ($message !== '') {
        $_SESSION['flash'] = ['ok', $message];
    }
    header('Location: ' . $url, true, 303);
    exit;
}

function handlePost(?array $user, int $eventId, string $page): never
{
    if (!hash_equals($_SESSION['csrf'], input('csrf'))) {
        http_response_code(403);
        exit('Formularen er udløbet. Gå tilbage og genindlæs siden.');
    }

    $eventLink = '?event=' . $eventId;
    $adminLink = '?page=admin&event=' . $eventId;
    $action = input('action');

    try {
        if ($action === 'logout') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', ['expires'=>1, 'path'=>$cookie['path'], 'secure'=>$cookie['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
            }
            session_destroy();
            go($eventLink);
        }

        if ($action === 'organizer' && $user) {
            if (isPlatformAdmin($user)) {
                throw new AccessDenied();
            }
            createOrganizer($user['id'], field('organizer_name', 'Arrangørens navn', 160));
            go($adminLink, 'Arrangøren er oprettet. Du kan nu oprette stævner.');
        }

        if (in_array($action, ['login','register','organizer'], true) && !$user) {
            $email = strtolower(field('email', 'E-mail', 254));
            $password = input('password');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 72 || str_contains($password, "\0")) {
                throw new InputError('Tjek e-mail og adgangskode.');
            }
            $account = row('SELECT * FROM users WHERE email = ?', [$email]);

            if ($action !== 'login') {
                $name = field('name', 'Navn', 120);
                if (strlen($password) < 8) {
                    throw new InputError('Vælg en adgangskode med mindst 8 tegn.');
                }
                if ($account) {
                    throw new InputError('E-mailadressen er allerede i brug. Prøv at logge ind.');
                }
                $organizerName = $action === 'organizer' ? field('organizer_name', 'Arrangørens navn', 160) : null;
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $id = atomic(function () use ($name, $email, $hash, $organizerName): int {
                    $created = insertUnique('INSERT INTO users (name,email,password) VALUES (?,?,?)', [$name,$email,$hash]);
                    if (!$created) {
                        throw new InputError('E-mailadressen er allerede i brug. Prøv at logge ind.');
                    }
                    $id = (int) db()->lastInsertId();
                    if ($organizerName !== null) {
                        createOrganizer($id, $organizerName);
                    }
                    return $id;
                });
            } else {
                $key = hash('sha256', $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
                $window = intdiv(time(), 600);
                $attempts = atomic(function () use ($key, $window): int {
                    $insert = usingMysql()
                        ? 'INSERT INTO login_attempts (`key`,`window`,`count`) VALUES (?,?,0) ON DUPLICATE KEY UPDATE `count`=`count`'
                        : 'INSERT OR IGNORE INTO login_attempts (`key`,`window`,`count`) VALUES (?,?,0)';
                    query($insert, [$key,$window]);
                    query('UPDATE login_attempts SET `count` = CASE WHEN `window` = ? THEN `count` + 1 ELSE 1 END, `window` = ? WHERE `key` = ?',
                        [$window,$window,$key]);
                    return (int) query('SELECT `count` FROM login_attempts WHERE `key`=?', [$key])->fetchColumn();
                });
                if ($attempts > 12) {
                    throw new InputError('For mange loginforsøg. Prøv igen om lidt.');
                }
                if (!password_verify($password, $account['password'] ?? '$2y$12$KnSjKRExyvlqf7dmpXvOMueheN7vYhBjlDNlm/fEM65tUpn4YB/wu') || !$account) {
                    throw new InputError('E-mail eller adgangskode er forkert.');
                }
                query('DELETE FROM login_attempts WHERE `key`=? OR `window` < ?', [$key, $window-24]);
                $id = $account['id'];
            }

            session_regenerate_id(true);
            $_SESSION = ['user' => $id, 'database' => databaseIdentity()];
            $signedIn = account($id);
            $landing = isPlatformAdmin($signedIn) ? '?page=platform' : (canManageEvents($signedIn) && !$eventId ? $adminLink : $eventLink);
            if ($action === 'organizer') {
                go($adminLink, 'Arrangøren er oprettet. Du kan nu oprette stævner.');
            }
            go($landing, $action === 'register' ? 'Din profil er oprettet.' : 'Du er logget ind.');
        }

        if (!$user) {
            go('?page=login&event=' . $eventId, 'Log ind for at fortsætte.');
        }

        if ($action === 'join' && $user['role'] === 'helper') {
            atomic(function () use ($eventId, $user): void {
                lockedRow('SELECT id FROM events WHERE id=?', [$eventId]);
                $shift = lockedRow('SELECT v.*,e.name AS event_name,o.owner_id,o.name AS organizer_name
                    FROM shifts v JOIN `groups` g ON g.id=v.group_id JOIN events e ON e.id=g.event_id
                    JOIN organizers o ON o.id=e.organizer_id
                    WHERE v.id=? AND e.id=? AND v.visible=1 AND v.bookable=1 AND g.visible=1 AND e.visible=1 AND o.active=1',
                    [(int) input('id'), $eventId]) ?? throw new InputError('Vi kunne ikke finde det valgte element.');
                if ($shift['starts_at'] <= time()) {
                    throw new InputError('Vagten er allerede begyndt.');
                }
                if (row('SELECT id FROM signups WHERE user_id=? AND shift_id=?', [$user['id'], $shift['id']])) {
                    throw new InputError('Du er allerede tilmeldt denne vagt.');
                }
                if (query('SELECT COUNT(*) FROM signups WHERE shift_id=?', [$shift['id']])->fetchColumn() >= $shift['capacity']) {
                    throw new InputError('Alle pladser er taget.');
                }
                query('INSERT INTO signups (user_id, shift_id, created_at) VALUES (?,?,?)', [$user['id'], $shift['id'], time()]);
                $recipients = $shift['owner_id'] === null
                    ? query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN)
                    : [$shift['owner_id']];
                foreach ($recipients as $recipient) {
                    notify($recipient, 'Ny tilmelding', "{$user['name']} har tilmeldt sig {$shift['name']} ved {$shift['event_name']} ({$shift['organizer_name']}).");
                }
            });
            go($eventLink, 'Du er tilmeldt vagten.');
        }

        if ($action === 'read_notices') {
            query('UPDATE notices SET read_at=? WHERE user_id=? AND read_at IS NULL', [time(), $user['id']]);
            go('?page=notices&event=' . $eventId);
        }

        if ($action === 'save_organizer') {
            if (!isPlatformAdmin($user)) {
                throw new AccessDenied();
            }
            saveOrganizer();
            go('?page=platform', 'Arrangøren er opdateret.');
        }

        if (!canManageEvents($user)) {
            throw new AccessDenied();
        }

        require __DIR__ . '/admin-actions.php';
        $savedEvent = atomic(fn() => saveAdmin($action, $eventId, $user));
        go('?page=admin&event=' . $savedEvent . ($action === 'contact' ? '#kontakter' : ''), 'Ændringen er gemt.');
    } catch (AccessDenied) {
        http_response_code(403);
        exit('Du har ikke adgang til denne handling.');
    } catch (InputError $error) {
        $_SESSION['flash'] = ['error', $error->getMessage()];
        go(match ($page) {
            'register', 'login', 'organizer' => '?page=' . $page . '&event=' . $eventId,
            'admin' => $adminLink,
            'platform' => '?page=platform',
            default => $eventLink,
        });
    }
}
