<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

startSecureSession();
$db = database();
$currentUser = currentUser($db);

if ($currentUser === null) {
    header('Location: login.php', true, 303);
    exit;
}

$error = null;
$success = $_SESSION['user_flash'] ?? null;
unset($_SESSION['user_flash']);
$csrfToken = ensureCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'unbekannte Aktion');
    $auditAttempted = false;
    try {
        validateCsrfToken($_POST);

        if ($action === 'add_user') {
            $newUsername = (string) ($_POST['username'] ?? '');
            $newUserId = createUser($db, $newUsername, (string) ($_POST['password'] ?? ''));
            $auditAttempted = true;
            recordAuditLog(
                $db,
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                auditActionName($action),
                ['neuer_benutzer_id' => $newUserId, 'neuer_benutzer' => $newUsername]
            );
            $_SESSION['user_flash'] = 'Der Benutzer wurde angelegt.';
        } elseif ($action === 'delete_user') {
            $deletedUsername = deleteUser($db, (int) ($_POST['user_id'] ?? 0), (int) $currentUser['id']);
            $auditAttempted = true;
            recordAuditLog(
                $db,
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                auditActionName($action),
                ['benutzer' => $deletedUsername]
            );
            $_SESSION['user_flash'] = sprintf('Der Benutzer „%s“ wurde gelöscht.', $deletedUsername);
        } elseif ($action === 'change_password') {
            changeUserPassword(
                $db,
                (int) $currentUser['id'],
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? '')
            );
            $auditAttempted = true;
            recordAuditLog(
                $db,
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                auditActionName($action)
            );
            $_SESSION['user_flash'] = 'Das Passwort wurde geändert.';
        } elseif ($action === 'logout') {
            $auditAttempted = true;
            recordAuditLog(
                $db,
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                auditActionName($action)
            );
            logoutUser($db);
            header('Location: login.php', true, 303);
            exit;
        } else {
            throw new DomainException('Diese Aktion ist nicht verfügbar.');
        }

        header('Location: users.php', true, 303);
        exit;
    } catch (DomainException $exception) {
        if (!$auditAttempted) {
            recordAuditLog(
                $db,
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                auditActionName($action) . ' (fehlgeschlagen)',
                ['status' => 'fehlgeschlagen']
            );
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Turnierverwaltung Benutzerverwaltung: ' . $exception->getMessage());
        if (!$auditAttempted) {
            recordAuditLog(
                $db,
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                auditActionName($action) . ' (fehlgeschlagen)',
                ['status' => 'fehlgeschlagen']
            );
        }
        $error = 'Die Benutzeränderung konnte wegen eines technischen Fehlers nicht verarbeitet werden.';
    }

    $csrfToken = ensureCsrfToken();
}

$userList = users($db);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10131b">
    <title>Benutzerverwaltung | DartBracket</title>
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <main class="users-shell">
        <header class="users-header">
            <a class="brand" href="index.php">
                <span class="brand-mark" aria-hidden="true">D</span>
                <span class="brand-copy"><strong>DARTBRACKET</strong><small>TURNIERVERWALTUNG</small></span>
            </a>
            <div class="users-header-actions">
                <span class="profile-copy"><strong><?= e($currentUser['username']) ?></strong><small>Angemeldet</small></span>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="button button-secondary" type="submit">Abmelden</button>
                </form>
            </div>
        </header>

        <div class="users-main">
            <a class="text-link" href="index.php">← Zurück zur Turnierübersicht</a>
            <div class="users-title">
                <div>
                    <p class="eyebrow">ZUGRIFF &amp; KONTEN</p>
                    <h1>Benutzerverwaltung<span class="heading-dot">.</span></h1>
                    <p class="welcome-copy">Alle Benutzer haben die gleichen Rechte zur Verwaltung von Turnieren und Konten.</p>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="flash-message flash-success" role="status"><?= e($success) ?></div>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <div class="flash-message flash-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="users-grid">
                <section class="panel">
                    <div class="panel-heading">
                        <div><p class="eyebrow">KONTEN</p><h2>Benutzer</h2></div>
                        <span class="count-badge"><?= count($userList) ?> BENUTZER</span>
                    </div>
                    <ul class="user-list">
                        <?php foreach ($userList as $user): ?>
                            <li>
                                <span class="participant-avatar" aria-hidden="true"><?= e(strtoupper(substr($user['username'], 0, 1))) ?></span>
                                <span class="user-name"><?= e($user['username']) ?><?= (int) $user['id'] === (int) $currentUser['id'] ? ' (du)' : '' ?></span>
                                <span class="user-created">Seit <?= e(date('d.m.Y', strtotime($user['created_at']))) ?></span>
                                <?php if ((int) $user['is_first_user'] === 1): ?>
                                    <span class="user-created">Erstkonto</span>
                                <?php elseif ((int) $user['id'] !== (int) $currentUser['id']): ?>
                                    <form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?= e(sprintf('Benutzer „%s“ wirklich löschen?', $user['username'])) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <button class="remove-button" type="submit">Löschen</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div><p class="eyebrow">NEUES KONTO</p><h2>Benutzer hinzufügen</h2></div>
                    </div>
                    <form class="user-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="add_user">
                        <div class="field">
                            <label for="new-username">Benutzername</label>
                            <input id="new-username" name="username" type="text" minlength="3" maxlength="32" pattern="[A-Za-z0-9._-]{3,32}" autocomplete="off" required>
                            <span class="field-hint">3–32 Zeichen: Buchstaben, Zahlen, Punkt, Bindestrich oder Unterstrich.</span>
                        </div>
                        <div class="field">
                            <label for="new-password">Passwort</label>
                            <input id="new-password" name="password" type="password" minlength="6" maxlength="1024" autocomplete="new-password" required>
                            <span class="field-hint">Mindestens 6 Zeichen.</span>
                        </div>
                        <button class="button button-primary" type="submit">Benutzer anlegen</button>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div><p class="eyebrow">SICHERHEIT</p><h2>Eigenes Passwort ändern</h2></div>
                    </div>
                    <form class="user-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="change_password">
                        <div class="field">
                            <label for="current-password">Aktuelles Passwort</label>
                            <input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
                        </div>
                        <div class="field">
                            <label for="change-new-password">Neues Passwort</label>
                            <input id="change-new-password" name="new_password" type="password" minlength="6" maxlength="1024" autocomplete="new-password" required>
                            <span class="field-hint">Mindestens 6 Zeichen.</span>
                        </div>
                        <button class="button button-primary" type="submit">Passwort speichern</button>
                    </form>
                </section>
            </div>
        </div>
    </main>
    <script src="presence.js" defer></script>
</body>
</html>
