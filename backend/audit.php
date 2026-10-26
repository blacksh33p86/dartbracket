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

$csrfToken = ensureCsrfToken();
$success = null;
$error = null;
$firstUser = isFirstUser($db, (int) $currentUser['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validateCsrfToken($_POST);
        if (($_POST['action'] ?? '') !== 'clear_audit_log') {
            throw new DomainException('Diese Aktion ist nicht verfügbar.');
        }

        $clearedCount = clearAuditLog($db, (int) $currentUser['id'], (string) $currentUser['username']);
        $_SESSION['audit_flash'] = sprintf(
            'Das Protokoll wurde geleert. %d alte Einträge entfernt; die Löschung wurde protokolliert.',
            $clearedCount
        );
        header('Location: audit.php', true, 303);
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Turnierverwaltung Protokoll: ' . $exception->getMessage());
        $error = 'Das Protokoll konnte wegen eines technischen Fehlers nicht geleert werden.';
    }
}

$success = $_SESSION['audit_flash'] ?? $success;
unset($_SESSION['audit_flash']);
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $page === false ? 1 : $page;
$pageSize = 100;
$entryCount = auditLogCount($db);
$pageCount = max(1, (int) ceil($entryCount / $pageSize));
$page = min($page, $pageCount);
$entries = auditLogEntries($db, $pageSize, ($page - 1) * $pageSize);

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
    <title>Protokoll | DartBracket</title>
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
                <a class="button button-secondary" href="index.php">Zur Übersicht</a>
            </div>
        </header>

        <div class="users-main">
            <div class="users-title">
                <div>
                    <p class="eyebrow">AKTIVITÄTEN</p>
                    <h1>Protokoll<span class="heading-dot">.</span></h1>
                    <p class="welcome-copy">Erfolgreiche Aktionen und Anmeldeversuche mit Nutzername und genauer Serverzeit.</p>
                </div>
                <div class="audit-header-actions">
                    <span class="count-badge"><?= $entryCount ?> EINTRÄGE</span>
                    <?php if ($firstUser): ?>
                        <form method="post" onsubmit="return confirm(this.dataset.confirm)" data-confirm="Das Protokoll wirklich leeren? Zur Nachvollziehbarkeit bleibt ein Eintrag über diese Löschung erhalten.">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="clear_audit_log">
                            <button class="button button-danger" type="submit" <?= $entryCount === 0 ? 'disabled' : '' ?>>Protokoll leeren</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($success !== null): ?>
                <div class="flash-message flash-success" role="status"><?= e($success) ?></div>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <div class="flash-message flash-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($entries === []): ?>
                <section class="panel audit-empty">
                    <strong>Noch keine Aktionen protokolliert</strong>
                    <p>Neue Aktionen erscheinen hier automatisch.</p>
                </section>
            <?php else: ?>
                <div class="audit-list">
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $timestamp = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $entry['created_at']);
                        $details = $entry['details'] === null
                            ? null
                            : json_decode((string) $entry['details'], true);
                        ?>
                        <article class="panel audit-entry">
                            <div class="audit-entry-heading">
                                <strong><?= e($entry['action']) ?></strong>
                                <time datetime="<?= e($timestamp ? $timestamp->format('Y-m-d\TH:i:s.u') : $entry['created_at']) ?>">
                                    <?= e($timestamp ? $timestamp->format('d.m.Y H:i:s.u') : $entry['created_at']) ?>
                                </time>
                            </div>
                            <div class="audit-entry-user">
                                <span class="avatar avatar-small" aria-hidden="true"><?= e(strtoupper(substr((string) $entry['username'], 0, 2))) ?></span>
                                <span><?= e($entry['username']) ?></span>
                            </div>
                            <?php if ($details !== null && $details !== []): ?>
                                <pre class="audit-details"><?= e(json_encode(
                                    $details,
                                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                ) ?: (string) $entry['details']) ?></pre>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($pageCount > 1): ?>
                    <nav class="audit-pagination" aria-label="Protokollseiten">
                        <?php if ($page > 1): ?>
                            <a class="button button-secondary" href="audit.php?page=<?= $page - 1 ?>">Neuere Einträge</a>
                        <?php endif; ?>
                        <span>Seite <?= $page ?> von <?= $pageCount ?></span>
                        <?php if ($page < $pageCount): ?>
                            <a class="button button-secondary" href="audit.php?page=<?= $page + 1 ?>">Ältere Einträge</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
