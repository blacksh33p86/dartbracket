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

    touchUserPresence($db, (int) $user['id']);

    return $user;
}

function touchUserPresence(PDO $db, int $userId): void
{
    $sessionToken = hash('sha256', session_id());
    $statement = $db->prepare(
        'INSERT INTO user_sessions (session_token, user_id, last_seen)
         VALUES (:session_token, :user_id, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen = CURRENT_TIMESTAMP'
    );
    $statement->execute(['session_token' => $sessionToken, 'user_id' => $userId]);
}

function removeCurrentUserPresence(PDO $db): void
{
    $statement = $db->prepare('DELETE FROM user_sessions WHERE session_token = :session_token');
    $statement->execute(['session_token' => hash('sha256', session_id())]);
}

function activeUsers(PDO $db): array
{
    $db->exec(
        'DELETE FROM user_sessions
         WHERE last_seen < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 MINUTE)'
    );

    return $db->query(
        'SELECT users.id, users.username
         FROM user_sessions
         INNER JOIN users ON users.id = user_sessions.user_id
         WHERE user_sessions.last_seen >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 MINUTE)
         GROUP BY users.id, users.username
         ORDER BY users.username'
    )->fetchAll();
}

function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function logoutUser(PDO $db): void
{
    removeCurrentUserPresence($db);
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

function recordAuditLog(
    PDO $db,
    ?int $userId,
    string $username,
    string $action,
    ?array $details = null
): void {
    $statement = $db->prepare(
        'INSERT INTO audit_log (user_id, username, action, details)
         VALUES (:user_id, :username, :action, :details)'
    );
    $statement->execute([
        'user_id' => $userId,
        'username' => mb_substr($username, 0, 32),
        'action' => mb_substr($action, 0, 80),
        'details' => $details === null
            ? null
            : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function auditActionName(string $action): string
{
    return match ($action) {
        'create_tournament' => 'Turnier angelegt',
        'archive_tournament' => 'Turnier archiviert',
        'delete_tournament' => 'Turnier gelöscht',
        'save_tournament' => 'Turnierdaten geändert',
        'save_payout' => 'Ausschüttung geändert',
        'add_participant' => 'Teilnehmer hinzugefügt',
        'import_participants' => 'Teilnehmer importiert',
        'assign_dartboard' => 'Dartboard geändert',
        'remove_participant' => 'Teilnehmer entfernt',
        'draw_tournament' => 'Turnier ausgelost',
        'save_result' => 'Ergebnis eingetragen',
        'edit_result' => 'Ergebnis geändert',
        'restore_tournament' => 'Turnier wiederhergestellt',
        'delete_archived_tournament' => 'Archiviertes Turnier gelöscht',
        'add_user' => 'Benutzer angelegt',
        'delete_user' => 'Benutzer gelöscht',
        'change_password' => 'Eigenes Passwort geändert',
        'login' => 'Anmeldung erfolgreich',
        'login_failed' => 'Anmeldeversuch fehlgeschlagen',
        'first_user_setup' => 'Ersten Benutzer eingerichtet',
        'logout' => 'Abmeldung',
        'clear_audit_log' => 'Protokoll geleert',
        default => 'Unbekannte Aktion',
    };
}

function auditLogEntries(PDO $db, int $limit, int $offset): array
{
    $statement = $db->prepare(
        'SELECT id, username, action, details, created_at
         FROM audit_log
         ORDER BY created_at DESC, id DESC
         LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->bindValue('offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function auditLogCount(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
}

function isFirstUser(PDO $db, int $userId): bool
{
    return (int) $db->query('SELECT MIN(id) FROM users')->fetchColumn() === $userId;
}

function clearAuditLog(PDO $db, int $userId, string $username): int
{
    $db->beginTransaction();

    try {
        $firstUser = $db->query('SELECT id FROM users ORDER BY id LIMIT 1 FOR UPDATE')->fetchColumn();
        if ($firstUser === false || (int) $firstUser !== $userId) {
            throw new DomainException('Nur der Erstbenutzer darf das Protokoll leeren.');
        }

        $entryCount = auditLogCount($db);
        $db->exec('DELETE FROM audit_log');
        recordAuditLog(
            $db,
            $userId,
            $username,
            'Protokoll geleert',
            ['entfernte_einträge' => $entryCount]
        );
        $db->commit();

        return $entryCount;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
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
