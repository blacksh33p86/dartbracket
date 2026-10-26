<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

startSecureSession();
$db = database();

if (currentUser($db) !== null) {
    header('Location: index.php', true, 303);
    exit;
}

$isFirstUser = userCount($db) === 0;
$error = null;
$username = '';
$csrfToken = ensureCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));

    try {
        validateCsrfToken($_POST);
        $password = (string) ($_POST['password'] ?? '');

        if ($isFirstUser && ($_POST['action'] ?? '') === 'create_first_user') {
            $userId = createFirstUser($db, $username, $password);
            loginUser(['id' => $userId, 'username' => $username]);
            header('Location: index.php', true, 303);
            exit;
        }

        if (!$isFirstUser && ($_POST['action'] ?? '') === 'login') {
            $user = authenticateUser($db, $username, $password);

            if ($user !== null) {
                loginUser($user);
                header('Location: index.php', true, 303);
                exit;
            }
        }

        if ($isFirstUser) {
            throw new DomainException('Bitte lege den ersten Benutzer mit einem Passwort an.');
        }

        throw new DomainException('Benutzername oder Passwort ist nicht korrekt.');
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Turnierverwaltung Login: ' . $exception->getMessage());
        $error = 'Die Anmeldung konnte wegen eines technischen Fehlers nicht verarbeitet werden.';
    }

    $isFirstUser = userCount($db) === 0;
    $csrfToken = ensureCsrfToken();
}

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
    <title><?= $isFirstUser ? 'Benutzer einrichten' : 'Anmelden' ?> | DartBracket</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <a class="brand auth-brand" href="login.php" aria-label="DartBracket">
            <span class="brand-mark" aria-hidden="true">D</span>
            <span class="brand-copy">
                <strong>DARTBRACKET</strong>
                <small>TURNIERVERWALTUNG</small>
            </span>
        </a>

        <section class="auth-card">
            <p class="eyebrow"><?= $isFirstUser ? 'ERSTEINRICHTUNG' : 'WILLKOMMEN ZURÜCK' ?></p>
            <h1><?= $isFirstUser ? 'Benutzer anlegen' : 'Anmelden' ?><span class="heading-dot">.</span></h1>
            <p class="auth-intro">
                <?= $isFirstUser
                    ? 'Lege den ersten Benutzer an, um den Zugang zum Backend einzurichten.'
                    : 'Melde dich an, um deine Turniere zu verwalten.' ?>
            </p>

            <?php if ($error !== null): ?>
                <div class="flash-message flash-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form class="auth-form" method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="<?= $isFirstUser ? 'create_first_user' : 'login' ?>">
                <div class="field">
                    <label for="username">Benutzername</label>
                    <input id="username" name="username" type="text" minlength="3" maxlength="32" pattern="[A-Za-z0-9._-]{3,32}" autocomplete="username" required autofocus value="<?= e($username) ?>">
                    <span class="field-hint">3–32 Zeichen: Buchstaben, Zahlen, Punkt, Bindestrich oder Unterstrich.</span>
                </div>
                <div class="field">
                    <label for="password">Passwort</label>
                    <input id="password" name="password" type="password" <?= $isFirstUser ? 'minlength="6"' : '' ?> maxlength="1024" autocomplete="<?= $isFirstUser ? 'new-password' : 'current-password' ?>" required>
                    <?php if ($isFirstUser): ?>
                        <span class="field-hint">Mindestens 6 Zeichen.</span>
                    <?php endif; ?>
                </div>
                <button class="button button-primary auth-submit" type="submit"><?= $isFirstUser ? 'Benutzer einrichten' : 'Anmelden' ?></button>
            </form>
        </section>
        <p class="auth-footer">DARTBRACKET <span>·</span> TURNIERVERWALTUNG</p>
    </main>
</body>
</html>
