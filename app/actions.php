<?php
declare(strict_types=1);

final class InputError extends RuntimeException {}

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

        if (($action === 'login' || $action === 'register') && !$user) {
            $email = strtolower(field('email', 'E-mail', 254));
            $password = input('password');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 72 || str_contains($password, "\0")) {
                throw new InputError('Tjek e-mail og adgangskode.');
            }
            $account = row('SELECT * FROM users WHERE email = ?', [$email]);

            if ($action === 'register') {
                $name = field('name', 'Navn', 120);
                if (strlen($password) < 8) {
                    throw new InputError('Vælg en adgangskode med mindst 8 tegn.');
                }
                if ($account) {
                    throw new InputError('E-mailadressen er allerede i brug. Prøv at logge ind.');
                }
                query('INSERT INTO users (name,email,password) VALUES (?,?,?) ON CONFLICT(email) DO NOTHING',
                    [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                if ((int) query('SELECT changes()')->fetchColumn() !== 1) {
                    throw new InputError('E-mailadressen er allerede i brug. Prøv at logge ind.');
                }
                $account = ['id'=>(int) db()->lastInsertId(), 'role'=>'helper'];
            } else {
                $key = hash('sha256', $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
                $window = intdiv(time(), 600);
                $attempts = query(
                    'INSERT INTO login_attempts (key,window,count) VALUES (?,?,1)
                     ON CONFLICT(key) DO UPDATE SET count = CASE WHEN window = excluded.window THEN count+1 ELSE 1 END, window = excluded.window
                     RETURNING count', [$key, $window]
                )->fetchColumn();
                if ($attempts > 12) {
                    throw new InputError('For mange loginforsøg. Prøv igen om lidt.');
                }
                if (!password_verify($password, $account['password'] ?? '$2y$12$KnSjKRExyvlqf7dmpXvOMueheN7vYhBjlDNlm/fEM65tUpn4YB/wu') || !$account) {
                    throw new InputError('E-mail eller adgangskode er forkert.');
                }
                query('DELETE FROM login_attempts WHERE key=? OR window < ?', [$key, $window-24]);
            }

            session_regenerate_id(true);
            $_SESSION = ['user' => $account['id']];
            go($account['role'] === 'admin' ? $adminLink : $eventLink, $action === 'register' ? 'Din profil er oprettet.' : 'Du er logget ind.');
        }

        if (!$user) {
            go('?page=login&event=' . $eventId, 'Log ind for at fortsætte.');
        }

        if ($action === 'join' && $user['role'] === 'helper') {
            atomic(function () use ($eventId, $user): void {
                $shift = requireRow('SELECT v.*, e.name event_name FROM shifts v JOIN groups g ON g.id=v.group_id
                    JOIN events e ON e.id=g.event_id WHERE v.id=? AND e.id=? AND v.visible=1 AND v.bookable=1 AND g.visible=1 AND e.visible=1',
                    [(int) input('id'), $eventId]);
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
                foreach (query("SELECT id FROM users WHERE role='admin'")->fetchAll() as $admin) {
                    notify($admin['id'], 'Ny tilmelding', "{$user['name']} har tilmeldt sig {$shift['name']} ved {$shift['event_name']}.");
                }
            });
            go($eventLink, 'Du er tilmeldt vagten.');
        }

        if ($action === 'read_notices') {
            query('UPDATE notices SET read_at=? WHERE user_id=? AND read_at IS NULL', [time(), $user['id']]);
            go('?page=notices&event=' . $eventId);
        }

        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('Denne handling kræver administratoradgang.');
        }

        require __DIR__ . '/admin-actions.php';
        $savedEvent = atomic(fn() => saveAdmin($action, $eventId));
        go('?page=admin&event=' . $savedEvent . ($action === 'contact' ? '#kontakter' : ''), 'Ændringen er gemt.');
    } catch (InputError $error) {
        $_SESSION['flash'] = ['error', $error->getMessage()];
        go(match ($page) {
            'register', 'login' => '?page=' . $page . '&event=' . $eventId,
            'admin' => $adminLink,
            default => $eventLink,
        });
    }
}
