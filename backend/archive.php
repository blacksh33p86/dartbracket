<?php
declare(strict_types=1);

require_once __DIR__ . '/tournament.php';
require_once __DIR__ . '/auth.php';

startSecureSession();
$db = database();
$currentUser = currentUser($db);

if ($currentUser === null) {
    header('Location: login.php', true, 303);
    exit;
}

$error = null;
$csrfToken = ensureCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'unbekannte Aktion');
    $auditAttempted = false;
    try {
        validateCsrfToken($_POST);
        if (!in_array($action, ['restore_tournament', 'delete_archived_tournament'], true)) {
            throw new DomainException('Diese Aktion ist nicht verfügbar.');
        }

        $tournamentId = filter_var($_POST['tournament_id'] ?? null, FILTER_VALIDATE_INT);

        if ($tournamentId === false || $tournamentId === null || $tournamentId < 1) {
            throw new DomainException('Bitte wähle ein gültiges Turnier aus.');
        }

        if ($action === 'restore_tournament') {
            restoreTournament($db, $tournamentId);
            $auditAttempted = true;
            recordAuditLog(
                $db,
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                auditActionName($action),
                ['turnier_id' => $tournamentId]
            );
            $_SESSION['active_tournament_id'] = $tournamentId;
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Das Turnier wurde wiederhergestellt.'];
            header('Location: index.php?tournament_id=' . $tournamentId, true, 303);
        } else {
            $deletedName = deleteArchivedTournament($db, $tournamentId);
            $auditAttempted = true;
            recordAuditLog(
                $db,
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                auditActionName($action),
                ['turnier_id' => $tournamentId, 'turnier' => $deletedName]
            );
            $_SESSION['archive_flash'] = sprintf('Das Turnier „%s“ und alle zugehörigen Daten wurden endgültig gelöscht.', $deletedName);
            header('Location: archive.php', true, 303);
        }
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
        error_log('Turnierverwaltung Archiv: ' . $exception->getMessage());
        if (!$auditAttempted) {
            recordAuditLog(
                $db,
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                auditActionName($action) . ' (fehlgeschlagen)',
                ['status' => 'fehlgeschlagen']
            );
        }
        $error = 'Die Archivaktion konnte wegen eines technischen Fehlers nicht ausgeführt werden.';
    }
}

$archive = archivedTournaments($db);
$success = $_SESSION['archive_flash'] ?? null;
unset($_SESSION['archive_flash']);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tournamentStatusLabel(string $status): string
{
    return match ($status) {
        'in_progress' => 'Läuft',
        'completed' => 'Beendet',
        default => 'Entwurf',
    };
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10131b">
    <title>Turnierarchiv | DartBracket</title>
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
                <a class="button button-secondary" href="index.php">Zur Übersicht</a>
            </div>
        </header>

        <div class="users-main">
            <div class="users-title">
                <div>
                    <p class="eyebrow">AUFBEWAHRTE TURNIERE</p>
                    <h1>Turnierarchiv<span class="heading-dot">.</span></h1>
                    <p class="welcome-copy">Archivierte Turniere sind aus der Turnierauswahl entfernt. Du kannst sie hier jederzeit wiederherstellen.</p>
                </div>
            </div>

            <?php if ($error !== null): ?>
                <div class="flash-message flash-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($success !== null): ?>
                <div class="flash-message flash-success" role="status"><?= e($success) ?></div>
            <?php endif; ?>

            <?php if ($archive === []): ?>
                <section class="panel empty-state">
                    <span class="empty-state-icon" aria-hidden="true">▤</span>
                    <strong>Das Archiv ist noch leer</strong>
                    <p>Archivierte Turniere erscheinen hier und können jederzeit wiederhergestellt werden.</p>
                    <a class="button button-secondary" href="index.php">Zur Turnierübersicht</a>
                </section>
            <?php else: ?>
                <section class="panel">
                    <div class="panel-heading">
                        <div><p class="eyebrow">ARCHIV</p><h2>Archivierte Turniere</h2></div>
                        <span class="count-badge"><?= count($archive) ?> TURNIERE</span>
                    </div>
                    <ul class="archive-list">
                        <?php foreach ($archive as $tournament): ?>
                            <li class="archive-item">
                                <div class="archive-copy">
                                    <strong><?= e($tournament['name']) ?></strong>
                                    <span>
                                        <?= $tournament['event_date'] ? e(date('d.m.Y', strtotime($tournament['event_date']))) . ' · ' : '' ?>
                                        <?= e(tournamentStatusLabel($tournament['status'])) ?>
                                    </span>
                                </div>
                                <div class="archive-actions">
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="restore_tournament">
                                        <input type="hidden" name="tournament_id" value="<?= (int) $tournament['id'] ?>">
                                        <button class="button button-primary" type="submit">Wiederherstellen</button>
                                    </form>
                                    <form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?= e(sprintf('Turnier „%s“ samt Teilnehmern, Paarungen und Ergebnissen endgültig löschen? Diese Aktion kann nicht rückgängig gemacht werden.', $tournament['name'])) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_archived_tournament">
                                        <input type="hidden" name="tournament_id" value="<?= (int) $tournament['id'] ?>">
                                        <button class="button button-danger" type="submit">Endgültig löschen</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </div>
    </main>
    <script src="presence.js" defer></script>
</body>
</html>
