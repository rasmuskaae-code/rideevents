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

        if (in_array($action, ['quick-login','forgot'], true)) {
            requestAuthLink($action === 'forgot' ? 'reset' : 'quick', $eventId);
            go('?page=' . $action . '&event=' . $eventId, 'Hvis der findes en konto med den e-mailadresse, modtager du et link.');
        }

        if (in_array($action, ['login-confirm','reset-password'], true)) {
            $reset = $action === 'reset-password';
            $token = input('token');
            $newPassword = null;
            if ($reset && validAuthToken($token)) {
                $password = input('password');
                if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0") || $password !== input('password_confirm')) {
                    $_SESSION['flash'] = ['error', 'Brug mindst 8 tegn, og indtast det samme kodeord i begge felter.'];
                    go('?page=reset-password#' . $token);
                }
                $newPassword = $password;
            }
            $link = redeemAuthLink($reset ? 'reset' : 'quick', $token, $newPassword);
            if (!$link) {
                $_SESSION['flash'] = ['error', 'Linket er udløbet eller har allerede været brugt. Bed om et nyt link.'];
                go($reset ? '?page=forgot' : '?page=quick-login');
            }
            if ($reset) {
                session_regenerate_id(true);
                $_SESSION = ['database'=>databaseIdentity()];
                go('?page=login&event=' . $link['event'], 'Dit kodeord er ændret. Log ind med dit nye kodeord.');
            }
            startUserSession($link['id'], $link['password']);
            $signedIn = account($link['id']);
            go(loginDestination($signedIn, $link['event']), 'Du er logget ind.');
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
                $attempts = atomic(fn() => countLoginAttempt($key, $window));
                if ($attempts > 12) {
                    throw new InputError('For mange loginforsøg. Prøv igen om lidt.');
                }
                if (!password_verify($password, $account['password'] ?? '$2y$12$KnSjKRExyvlqf7dmpXvOMueheN7vYhBjlDNlm/fEM65tUpn4YB/wu') || !$account) {
                    throw new InputError('E-mail eller adgangskode er forkert.');
                }
                query('DELETE FROM login_attempts WHERE `key`=? OR `window` < ?', [$key, $window-24]);
                $id = $account['id'];
            }

            startUserSession($id, $action === 'login' ? $account['password'] : $hash);
            $signedIn = account($id);
            $landing = loginDestination($signedIn, $eventId);
            if ($action === 'organizer') {
                go($adminLink, 'Arrangøren er oprettet. Du kan nu oprette stævner.');
            }
            go($landing, $action === 'register' ? 'Din profil er oprettet.' : 'Du er logget ind.');
        }

        if (!$user) {
            go('?page=login&event=' . $eventId, 'Log ind for at fortsætte.');
        }

        if ($action === 'buy') {
            startCheckout($user, input('plan'));
        }

        if ($action === 'apply') {
            applyAsHelper($user, $eventId);
            go($eventLink, 'Din ansøgning er sendt til arrangøren.');
        }

        if ($action === 'join' && $user['role'] === 'helper') {
            atomic(function () use ($eventId, $user): void {
                lockedRow('SELECT id FROM events WHERE id=?', [$eventId]);
                $shift = lockedRow('SELECT v.*,e.name AS event_name,o.owner_id,o.name AS organizer_name
                    FROM shifts v JOIN `groups` g ON g.id=v.group_id JOIN events e ON e.id=g.event_id
                    JOIN organizers o ON o.id=e.organizer_id
                    WHERE v.id=? AND e.id=? AND v.visible=1 AND v.bookable=1 AND g.visible=1 AND e.visible=1 AND o.active=1',
                    [(int) input('id'), $eventId]) ?? throw new InputError('Vi kunne ikke finde det valgte element.');
                if (!row("SELECT user_id FROM helper_applications WHERE event_id=? AND user_id=? AND status='approved'", [$eventId,$user['id']])) {
                    throw new InputError('Arrangøren skal godkende dig, før du kan tilmelde dig en vagt.');
                }
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
                notifyOrganizer($shift['owner_id'], 'Ny tilmelding', "{$user['name']} har tilmeldt sig {$shift['name']} ved {$shift['event_name']} ({$shift['organizer_name']}).");
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
        if ($action === 'application') {
            go($adminLink . '#ansoegninger', input('decision') === 'approved' ? 'Hjælperen er godkendt.' : 'Ansøgningen er afvist.');
        }
        if ($action === 'delete') {
            $label = match (input('kind')) {
                'event' => 'Stævnet',
                'group' => 'Gruppen',
                'shift' => 'Posten',
            };
            go($savedEvent ? $adminLink . '#groups-heading' : '?page=admin', "$label er slettet.");
        }
        go('?page=admin&event=' . $savedEvent . ($action === 'contact' ? '#kontakter' : ''), 'Ændringen er gemt.');
    } catch (BillingLimit $error) {
        $_SESSION['flash'] = ['error', $error->getMessage()];
        go(isPlatformAdmin($user) ? $adminLink : '?page=billing');
    } catch (AccessDenied) {
        http_response_code(403);
        exit('Du har ikke adgang til denne handling.');
    } catch (InputError $error) {
        $_SESSION['flash'] = ['error', $error->getMessage()];
        go(match ($page) {
            'register', 'login', 'organizer', 'forgot', 'quick-login' => '?page=' . $page . '&event=' . $eventId,
            'admin', 'delete' => $adminLink,
            'platform' => '?page=platform',
            'billing' => '?page=billing',
            default => $eventLink,
        });
    }
}
