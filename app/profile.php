<?php
declare(strict_types=1);

function updateProfile(array $user, string $action): void
{
    $passwordHash = atomic(function () use ($user, $action): ?string {
        $current = lockedRow('SELECT email,password FROM users WHERE id=?', [$user['id']]) ?? throw new AccessDenied();
        if (!hash_equals($current['password'], $user['password'])) {
            throw new AccessDenied();
        }
        if ($action === 'profile-mail') {
            $previous = row('SELECT email_notifications FROM user_preferences WHERE user_id=?', [$user['id']]);
            $enabled = flag('email_notifications');
            if ($previous && (int) $previous['email_notifications'] !== $enabled) {
                $latest = (int) query('SELECT MAX(id) FROM notices WHERE user_id=?', [$user['id']])->fetchColumn();
                query('UPDATE user_preferences SET email_notifications=?,email_notice_after_id=? WHERE user_id=?',
                    [$enabled,$latest,$user['id']]);
            } elseif (!$previous && !$enabled) {
                query('INSERT INTO user_preferences (user_id,email_notifications) VALUES (?,0)', [$user['id']]);
            }
            return null;
        }

        $password = input('current_password');
        if ($action === 'profile-password') {
            confirmCurrentPassword($password, $current['password']);
            $new = input('password');
            if (strlen($new) < 8 || strlen($new) > 72 || str_contains($new, "\0") || $new !== input('password_confirm')) {
                throw new InputError('Brug mindst 8 tegn, og indtast det samme kodeord i begge felter.');
            }
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            query('UPDATE users SET password=? WHERE id=?', [$newHash,$user['id']]);
        } else {
            $name = field('name', 'Navn', 120);
            $email = strtolower(field('email', 'E-mail', 254));
            $phone = field('phone', 'Telefon', 32, false);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InputError('Indtast en gyldig e-mailadresse.');
            }
            if ($phone !== '' && !preg_match('/^\+?[0-9() .-]*[0-9][0-9() .-]*$/D', $phone)) {
                throw new InputError('Indtast et gyldigt telefonnummer.');
            }

            $newHash = null;
            if ($email !== $current['email']) {
                confirmCurrentPassword($password, $current['password']);
                $newHash = password_hash($password, PASSWORD_DEFAULT);
            }
            try {
                query('UPDATE users SET name=?,email=?,password=? WHERE id=?',
                    [$name,$email,$newHash ?? $current['password'],$user['id']]);
            } catch (PDOException $error) {
                if ((string) $error->getCode() === '23000') {
                    throw new InputError('E-mailadressen er allerede i brug.');
                }
                throw $error;
            }

            if (row('SELECT user_id FROM user_preferences WHERE user_id=?', [$user['id']])) {
                query('UPDATE user_preferences SET phone=? WHERE user_id=?', [$phone,$user['id']]);
            } elseif ($phone !== '') {
                query('INSERT INTO user_preferences (user_id,phone) VALUES (?,?)', [$user['id'],$phone]);
            }
        }

        if ($newHash !== null) {
            query('DELETE FROM auth_links WHERE user_id=?', [$user['id']]);
        }
        return $newHash;
    });

    if ($passwordHash !== null) {
        startUserSession($user['id'], $passwordHash);
    }
}

function confirmCurrentPassword(string $password, string $hash): void
{
    if (strlen($password) > 72 || str_contains($password, "\0") || !password_verify($password, $hash)) {
        throw new InputError('Det nuværende kodeord er forkert.');
    }
}
