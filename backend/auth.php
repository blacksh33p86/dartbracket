<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'use_only_cookies' => true,
    ]);
}

function ensureCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validateCsrfToken(array $input): void
{
    $submittedToken = (string) ($input['csrf_token'] ?? '');

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        throw new DomainException('Die Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.');
    }
}

function userCount(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function validateUsername(string $username): string
{
    $username = trim($username);

    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/D', $username)) {
        throw new DomainException('Der Benutzername muss 3–32 Zeichen haben und darf Buchstaben, Zahlen, Punkt, Bindestrich oder Unterstrich enthalten.');
    }

    return $username;
}

function validatePassword(string $password): void
{
    if (strlen($password) < 6 || strlen($password) > 1024) {
        throw new DomainException('Das Passwort muss zwischen 6 und 1024 Zeichen lang sein.');
    }
}

function createFirstUser(PDO $db, string $username, string $password): int
{
    $username = validateUsername($username);
    validatePassword($password);
    $statement = $db->prepare(
        'INSERT INTO users (username, password_hash)
         SELECT :username, :password_hash
         WHERE NOT EXISTS (SELECT 1 FROM users)'
    );
    $statement->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    if ($statement->rowCount() !== 1) {
        throw new DomainException('Die Ersteinrichtung wurde bereits abgeschlossen. Bitte melde dich an.');
    }

    return (int) $db->lastInsertId();
}

function createUser(PDO $db, string $username, string $password): int
{
    $username = validateUsername($username);
    validatePassword($password);

    try {
        $statement = $db->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
        $statement->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $exception) {
        if (
            str_contains($exception->getMessage(), 'UNIQUE constraint failed: users.username')
            || ($exception->getCode() === '23000'
                && str_contains(strtolower($exception->getMessage()), 'users_username_unique'))
        ) {
            throw new DomainException('Dieser Benutzername ist bereits vergeben.');
        }

        throw $exception;
    }

    return (int) $db->lastInsertId();
}

function authenticateUser(PDO $db, string $username, string $password): ?array
{
    $statement = $db->prepare('SELECT id, username, password_hash FROM users WHERE username = :username');
    $statement->execute(['username' => trim($username)]);
    $user = $statement->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $update = $db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $update->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $user['id'],
        ]);
    }

    unset($user['password_hash']);

    return $user;
}

function currentUser(PDO $db): ?array
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);

    if ($userId === false || $userId === null) {
        return null;
    }

    $statement = $db->prepare('SELECT id, username FROM users WHERE id = :id');
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function users(PDO $db): array
{
    return $db->query(
        'SELECT id, username, created_at, id = (SELECT MIN(id) FROM users) AS is_first_user
         FROM users
         ORDER BY username'
    )->fetchAll();
}

function deleteUser(PDO $db, int $userId, int $currentUserId): string
{
    if ($userId === $currentUserId) {
        throw new DomainException('Du kannst deinen eigenen Benutzer nicht löschen.');
    }

    $user = $db->prepare('SELECT username FROM users WHERE id = :id');
    $user->execute(['id' => $userId]);
    $username = $user->fetchColumn();

    if ($username === false) {
        throw new DomainException('Der Benutzer wurde nicht gefunden.');
    }

    $firstUserId = (int) $db->query('SELECT MIN(id) FROM users')->fetchColumn();

    if ($userId === $firstUserId) {
        throw new DomainException('Der erste Benutzer kann nicht gelöscht werden.');
    }

    $delete = $db->prepare('DELETE FROM users WHERE id = :id AND id > :first_user_id');
    $delete->execute(['id' => $userId, 'first_user_id' => $firstUserId]);

    if ($delete->rowCount() !== 1) {
        throw new DomainException('Der Benutzer wurde nicht gefunden oder kann nicht gelöscht werden.');
    }

    return (string) $username;
}

function changeUserPassword(PDO $db, int $userId, string $currentPassword, string $newPassword): void
{
    validatePassword($newPassword);
    $statement = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
    $statement->execute(['id' => $userId]);
    $passwordHash = $statement->fetchColumn();

    if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
        throw new DomainException('Das aktuelle Passwort ist nicht korrekt.');
    }

    $update = $db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $update->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $userId,
    ]);
}
