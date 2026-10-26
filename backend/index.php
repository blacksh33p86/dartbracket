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

$tournaments = tournaments($db);

if (isset($_GET['tournament_id'])) {
    $requestedTournamentId = filter_var($_GET['tournament_id'], FILTER_VALIDATE_INT);
    $requestedTournament = $requestedTournamentId === false ? null : tournamentById($db, $requestedTournamentId);

    if ($requestedTournament === null || (int) $requestedTournament['archived'] === 1) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Das ausgewählte Turnier wurde nicht gefunden oder ist archiviert.'];
        unset($_SESSION['active_tournament_id']);
    } else {
        $_SESSION['active_tournament_id'] = (int) $requestedTournament['id'];
    }
}

$activeTournamentId = filter_var($_SESSION['active_tournament_id'] ?? null, FILTER_VALIDATE_INT);
$current = $activeTournamentId === false || $activeTournamentId === null
    ? null
    : tournamentById($db, $activeTournamentId);

if ($current !== null && (int) $current['archived'] === 1) {
    $current = null;
    unset($_SESSION['active_tournament_id']);
}

if ($current === null && $tournaments !== []) {
    $current = currentTournament($db);
    $_SESSION['active_tournament_id'] = (int) $current['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'logout') {
        try {
            validateCsrfToken($_POST);
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
            header('Location: index.php', true, 303);
            exit;
        }

        logoutUser();
        header('Location: login.php', true, 303);
        exit;
    }

    $redirectSection = match ($_POST['action'] ?? '') {
        'add_participant', 'remove_participant', 'import_participants' => 'teilnehmer',
        'save_payout' => 'ausschuettung',
        'draw_tournament', 'save_result', 'edit_result', 'assign_dartboard' => 'auslosung',
        default => 'turnier',
    };

    try {
        validateCsrfToken($_POST);

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_tournament') {
            $createdTournamentId = createTournament($db);
            $_SESSION['active_tournament_id'] = $createdTournamentId;
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Ein neues Turnier wurde angelegt.'];
        } elseif ($action === 'archive_tournament') {
            $tournamentId = (int) ($_POST['tournament_id'] ?? 0);

            if ($current === null || $tournamentId !== (int) $current['id']) {
                throw new DomainException('Das ausgewählte Turnier stimmt nicht mehr überein. Bitte lade die Seite neu.');
            }

            $archivedName = archiveTournament($db, $tournamentId);
            unset($_SESSION['active_tournament_id']);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => sprintf('Das Turnier „%s“ wurde archiviert. Es ist nicht mehr in der Turnierauswahl verfügbar.', $archivedName),
            ];
        } elseif ($action === 'delete_tournament') {
            $tournamentId = (int) ($_POST['tournament_id'] ?? 0);

            if ($current === null || $tournamentId !== (int) $current['id']) {
                throw new DomainException('Das ausgewählte Turnier stimmt nicht mehr überein. Bitte lade die Seite neu.');
            }

            $deletedName = deleteTournament($db, $tournamentId);
            unset($_SESSION['active_tournament_id']);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => sprintf('Das Turnier „%s“ und alle zugehörigen Daten wurden gelöscht.', $deletedName),
            ];
        } elseif ($action === 'save_tournament') {
            $postedTournamentId = (int) ($_POST['tournament_id'] ?? 0);

            if ($postedTournamentId !== 0 && ($current === null || $postedTournamentId !== (int) $current['id'])) {
                throw new DomainException('Dieses Turnier ist nicht mehr aktuell. Bitte lade die Seite neu.');
            }

            $savedTournamentId = saveTournament($db, $_POST, $current);
            $_SESSION['active_tournament_id'] = $savedTournamentId;
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Die Turnierdaten wurden gespeichert.'];
        } elseif ($action === 'save_payout') {
            $tournamentId = (int) ($_POST['tournament_id'] ?? 0);

            if ($current === null || $tournamentId !== (int) $current['id']) {
                throw new DomainException('Das Turnier wurde nicht gefunden. Bitte lade die Seite neu.');
            }

            savePayoutSettings($db, $tournamentId, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Die Ausschüttung wurde gespeichert.'];
        } else {
            $tournamentId = (int) ($_POST['tournament_id'] ?? 0);

            if ($current === null || $tournamentId !== (int) $current['id']) {
                throw new DomainException('Das Turnier wurde nicht gefunden. Bitte lade die Seite neu.');
            }

            if ($action === 'add_participant') {
                addParticipant($db, $tournamentId, (string) ($_POST['player_name'] ?? ''));
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Der Teilnehmer wurde hinzugefügt.'];
            } elseif ($action === 'import_participants') {
                $sourceTournamentId = filter_var(
                    $_POST['source_tournament_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($sourceTournamentId === false) {
                    throw new DomainException('Bitte wähle ein Quellturnier aus.');
                }

                $importResult = importParticipants($db, $tournamentId, $sourceTournamentId);
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => sprintf(
                        'Spielerimport abgeschlossen: %d übernommen, %d wegen bereits vorhandener Namen übersprungen.',
                        $importResult['imported'],
                        $importResult['skipped']
                    ),
                ];
            } elseif ($action === 'assign_dartboard') {
                assignDartboard(
                    $db,
                    $tournamentId,
                    (int) ($_POST['match_id'] ?? 0),
                    (string) ($_POST['board_label'] ?? '')
                );
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Das Dartboard wurde der Paarung zugewiesen.'];
            } elseif ($action === 'remove_participant') {
                removeParticipant($db, $tournamentId, (int) ($_POST['participant_id'] ?? 0));
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Der Teilnehmer wurde entfernt.'];
            } elseif ($action === 'draw_tournament') {
                drawTournament($db, $tournamentId);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Die Paarungen wurden zufällig ausgelost.'];
            } elseif ($action === 'save_result') {
                $scoreOne = filter_var($_POST['score_one'] ?? null, FILTER_VALIDATE_INT);
                $scoreTwo = filter_var($_POST['score_two'] ?? null, FILTER_VALIDATE_INT);

                if ($scoreOne === false || $scoreTwo === false) {
                    throw new DomainException('Bitte gib für beide Spieler ein ganzzahliges Ergebnis ein.');
                }

                recordResult($db, $tournamentId, (int) ($_POST['match_id'] ?? 0), $scoreOne, $scoreTwo);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Das Ergebnis wurde gespeichert.'];
            } elseif ($action === 'edit_result') {
                $scoreOne = filter_var($_POST['score_one'] ?? null, FILTER_VALIDATE_INT);
                $scoreTwo = filter_var($_POST['score_two'] ?? null, FILTER_VALIDATE_INT);

                if ($scoreOne === false || $scoreTwo === false) {
                    throw new DomainException('Bitte gib für beide Spieler ein ganzzahliges Ergebnis ein.');
                }

                editResult($db, $tournamentId, (int) ($_POST['match_id'] ?? 0), $scoreOne, $scoreTwo);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Das Ergebnis wurde geändert.'];
            } else {
                throw new DomainException('Diese Aktion ist nicht verfügbar.');
            }
        }
    } catch (DomainException $exception) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
    } catch (Throwable $exception) {
        error_log('Turnierverwaltung: ' . $exception->getMessage());
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => 'Die Änderung konnte wegen eines technischen Fehlers nicht gespeichert werden.',
        ];
    }

    $redirectTournamentId = filter_var($_SESSION['active_tournament_id'] ?? null, FILTER_VALIDATE_INT);
    $redirectQuery = $redirectTournamentId !== false && $redirectTournamentId !== null
        ? '?tournament_id=' . $redirectTournamentId
        : '';
    header('Location: index.php' . $redirectQuery . '#' . $redirectSection, true, 303);
    exit;
}

$csrfToken = ensureCsrfToken();
$tournaments = tournaments($db);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$participants = $current ? participantsForTournament($db, (int) $current['id']) : [];
$participantImportTournaments = $current
    ? array_values(array_filter(
        $tournaments,
        static fn (array $tournament): bool => (int) $tournament['id'] !== (int) $current['id']
    ))
    : [];
$matches = $current ? matchesForTournament($db, (int) $current['id']) : [];
$upcomingPreview = upcomingMatchPreviews($db, $current, $matches);
$rounds = [];
$latestPlayedRound = 0;
foreach ($matches as $match) {
    $rounds[$match['round_number']][] = $match;
    if ($match['status'] === 'completed') {
        $latestPlayedRound = max($latestPlayedRound, (int) $match['round_number']);
    }
}
$matchDisplayLabels = matchDisplayLabels($matches, $current);
$participantCount = count($participants);
$payout = payoutForTournament($current, $participantCount);
$pendingCount = count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'pending'));
$remainingMatchCount = totalMatchCountForTournament($current, $participantCount, $matches);
$playedMatchCount = count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'completed'));
$isLocked = $current !== null && $current['status'] !== 'draft';
$canEditDetails = $current === null || $current['status'] !== 'completed';
$progress = !$current || !$current['mode']
    ? 0
    : ($current['status'] === 'completed' ? 100 : ($isLocked ? 66 : 33));
$statusLabel = match ($current['status'] ?? null) {
    'in_progress' => 'Turnier läuft',
    'completed' => 'Turnier beendet',
    'draft' => 'Turnier nicht ausgelost',
    default => 'Kein Turnier aktiv',
};
$winner = null;
$standings = $current ? standingsForTournament($db, $current) : [];
if ($current && $current['status'] === 'completed') {
    if (($current['format'] ?? 'knockout') === 'round_robin') {
        $winner = $standings['Rundenturnier'][0]['name'] ?? null;
    } else {
        $winner = $db->prepare(
            'SELECT name FROM participants
             WHERE tournament_id = :id AND losses < :losses
             ORDER BY losses, id LIMIT 1'
        );
        $winner->execute([
            'id' => $current['id'],
            'losses' => $current['mode'] === 'double_ko' ? 2 : 1,
        ]);
        $winner = $winner->fetchColumn() ?: null;
    }
}
$formMode = match ($current['format'] ?? 'knockout') {
    'round_robin' => 'round_robin',
    'groups_ko' => 'groups_ko',
    default => $current['mode'] ?? '',
};

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
    <title>Turnierverwaltung | DartBracket</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar" aria-label="Hauptnavigation">
            <a class="brand" href="#start" aria-label="DartBracket Startseite">
                <span class="brand-mark" aria-hidden="true">D</span>
                <span class="brand-copy">
                    <strong>DARTBRACKET</strong>
                    <small>TURNIERVERWALTUNG</small>
                </span>
            </a>

            <div class="sidebar-label">MENÜ</div>
            <nav class="navigation">
                <a class="nav-link active" href="#start" aria-current="page">
                    <span class="nav-icon" aria-hidden="true">⌂</span>
                    Übersicht
                </a>
                <a class="nav-link" href="#turnier">
                    <span class="nav-icon" aria-hidden="true">◎</span>
                    Turnier einrichten
                </a>
                <a class="nav-link" href="#ausschuettung">
                    <span class="nav-icon" aria-hidden="true">%</span>
                    Ausschüttung
                </a>
                <a class="nav-link" href="#teilnehmer">
                    <span class="nav-icon" aria-hidden="true">♙</span>
                    Teilnehmer
                </a>
                <a class="nav-link" href="#auslosung">
                    <span class="nav-icon" aria-hidden="true">⌘</span>
                    Auslosung
                </a>
                <a class="nav-link" href="archive.php">
                    <span class="nav-icon" aria-hidden="true">▤</span>
                    Turnierarchiv
                </a>
                <a class="nav-link" href="users.php">
                    <span class="nav-icon" aria-hidden="true">♙</span>
                    Benutzerverwaltung
                </a>
            </nav>

            <div class="sidebar-note">
                <span class="note-dot" aria-hidden="true"></span>
                <div>
                    <strong>Dein Turnierplatz</strong>
                    <p>Alles bereit für den nächsten Dartabend?</p>
                </div>
            </div>

            <div class="sidebar-footer">
                <span class="avatar" aria-hidden="true"><?= e(strtoupper(substr($currentUser['username'], 0, 2))) ?></span>
                <span class="profile-copy"><strong><?= e($currentUser['username']) ?></strong><small>Angemeldet</small></span>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="sidebar-logout-button" type="submit" aria-label="Abmelden" title="Abmelden">↪</button>
                </form>
            </div>
        </aside>

        <main class="main-content" id="start">
            <header class="topbar">
                <div class="breadcrumbs"><span>Workspace</span><span aria-hidden="true">/</span><strong>Übersicht</strong></div>
                <div class="topbar-actions">
                    <span class="status-pill"><span class="status-dot" aria-hidden="true"></span> <?= e($statusLabel) ?></span>
                    <form method="get" class="tournament-switcher">
                        <label class="visually-hidden" for="active-tournament">Turnier auswählen</label>
                        <select id="active-tournament" name="tournament_id" <?= $tournaments === [] ? 'disabled' : '' ?>>
                            <?php if ($tournaments === []): ?>
                                <option>Keine Turniere vorhanden</option>
                            <?php else: ?>
                                <?php foreach ($tournaments as $tournament): ?>
                                    <?php
                                    $optionStatus = match ($tournament['status']) {
                                        'in_progress' => 'Läuft',
                                        'completed' => 'Beendet',
                                        default => 'Entwurf',
                                    };
                                    ?>
                                    <option value="<?= (int) $tournament['id'] ?>" <?= $current && (int) $current['id'] === (int) $tournament['id'] ? 'selected' : '' ?>>
                                        <?= e($tournament['name']) ?><?= $tournament['event_date'] ? ' · ' . e(date('d.m.Y', strtotime($tournament['event_date']))) : '' ?> · <?= e($optionStatus) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <button class="button button-secondary switch-button" type="submit" <?= $tournaments === [] ? 'disabled' : '' ?>>Wechseln</button>
                    </form>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_tournament">
                        <button class="button button-secondary new-tournament-button" type="submit">＋ Neues Turnier</button>
                    </form>
                    <button class="icon-button" type="button" aria-label="Benachrichtigungen">♧</button>
                    <span class="avatar avatar-small" aria-label="<?= e($currentUser['username']) ?>"><?= e(strtoupper(substr($currentUser['username'], 0, 2))) ?></span>
                </div>
            </header>

            <div class="page-wrap">
                <section class="welcome-row">
                    <div>
                        <p class="eyebrow">DEIN DARTABEND, DEIN TURNIER</p>
                        <h1>Turnierübersicht<span class="heading-dot">.</span></h1>
                        <p class="welcome-copy">Richte ein Turnier ein und bring alle an die Oche.</p>
                    </div>
                    <div class="welcome-actions">
                        <?php if ($current): ?>
                            <a class="button button-secondary" href="screen.php?tournament_id=<?= (int) $current['id'] ?>" target="_blank" rel="noopener">Beameransicht öffnen ↗</a>
                            <a class="button button-secondary" href="tree.php?tournament_id=<?= (int) $current['id'] ?>" target="_blank" rel="noopener">Turnierbaum öffnen ↗</a>
                            <form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?= e(sprintf('Turnier „%s“ ins Archiv verschieben? Du kannst es später wiederherstellen.', $current['name'])) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="archive_tournament">
                                <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                <button class="button button-secondary" type="submit">Archivieren</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?= e(sprintf('Turnier „%s“ samt Teilnehmern, Paarungen und Ergebnissen endgültig löschen?', $current['name'])) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_tournament">
                                <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                <button class="button button-danger" type="submit">Turnier löschen</button>
                            </form>
                        <?php endif; ?>
                        <a class="button button-primary" href="#turnier"><span aria-hidden="true">＋</span> <?= $current ? 'Turnier bearbeiten' : 'Turnier einrichten' ?></a>
                    </div>
                </section>

                <?php if ($flash): ?>
                    <div class="flash-message flash-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>">
                        <?= e($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <section class="stats-grid" aria-label="Turnierstatus">
                    <article class="stat-card">
                        <div class="stat-heading"><span>AKTUELLES TURNIER</span><span class="stat-icon icon-purple" aria-hidden="true">◎</span></div>
                        <strong class="stat-value stat-empty"><?= $current ? e($current['name']) : 'Noch keines' ?></strong>
                        <span class="stat-caption"><?= $current && $current['event_date'] ? e(date('d.m.Y', strtotime($current['event_date']))) : 'Erstelle ein Turnier, um loszulegen' ?></span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-heading"><span>TEILNEHMER</span><span class="stat-icon icon-blue" aria-hidden="true">♙</span></div>
                        <strong class="stat-value"><?= $participantCount ?> <span class="stat-unit">Spieler</span></strong>
                        <span class="stat-caption"><?= $participantCount ? 'Auf der Teilnehmerliste' : 'Noch keine Spieler eingetragen' ?></span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-heading"><span>BEVORSTEHENDE SPIELE</span><span class="stat-icon icon-orange" aria-hidden="true">↗</span></div>
                        <strong class="stat-value"><?= $current && $isLocked ? $pendingCount : '—' ?></strong>
                        <span class="stat-caption"><?= $pendingCount === 1 ? 'Spiel noch offen' : 'Offene Spiele' ?></span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-heading"><span>STARTGEBÜHR</span><span class="stat-icon icon-green" aria-hidden="true">€</span></div>
                        <strong class="stat-value"><?= $current ? e(number_format((int) $current['entry_fee_cents'] / 100, 2, ',', '.')) . ' €' : '—' ?></strong>
                        <span class="stat-caption"><?= $current ? 'Startgebühr pro Person' : 'Wird beim Einrichten festgelegt' ?></span>
                    </article>
                </section>

                <section class="panel payout-panel" id="ausschuettung">
                    <div class="panel-heading payout-heading">
                        <div>
                            <p class="eyebrow">STARTGEBÜHREN</p>
                            <h2>Ausschüttung</h2>
                        </div>
                        <?php if ($current): ?>
                        <span class="step-badge">
                            <?= e(number_format((int) $current['payout_percent_basis_points'] / 100, 2, ',', '.')) ?> %
                            · <?= ($current['payout_distribution'] ?? 'flat') === 'top_heavy' ? 'KOPFLASTIG' : 'FLACH ABFALLEND' ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($current): ?>
                        <div class="payout-layout">
                            <div class="payout-summary">
                                <div class="payout-totals">
                                    <div>
                                        <span>Startgebühren gesamt</span>
                                        <strong><?= e(number_format($payout['total_cents'] / 100, 2, ',', '.')) ?> €</strong>
                                        <small><?= $participantCount ?> × <?= e(number_format((int) $current['entry_fee_cents'] / 100, 2, ',', '.')) ?> €</small>
                                    </div>
                                    <div class="payout-pool">
                                        <span>Ausschüttungstopf</span>
                                        <strong><?= e(number_format($payout['pool_cents'] / 100, 2, ',', '.')) ?> €</strong>
                                        <small>
                                            <?= e(number_format((int) $current['payout_percent_basis_points'] / 100, 2, ',', '.')) ?> %
                                            · <?= ($current['payout_distribution'] ?? 'flat') === 'top_heavy' ? 'kopflastig' : 'flach abfallend' ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="payout-list-heading">
                                    <strong>Auszahlung je Platz</strong>
                                    <span><?= count($payout['places']) ?> von <?= (int) $current['payout_places'] ?> Plätzen</span>
                                </div>
                                <?php if ($payout['places']): ?>
                                    <ol class="payout-list">
                                        <?php foreach ($payout['places'] as $place): ?>
                                            <li>
                                                <span class="place-number"><?= (int) $place['place'] ?></span>
                                                <span><?= (int) $place['place'] === 1 ? '1. Platz' : (int) $place['place'] . '. Platz' ?></span>
                                                <strong><?= e(number_format($place['amount_cents'] / 100, 2, ',', '.')) ?> €</strong>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php else: ?>
                                    <p class="payout-empty">Sobald Teilnehmer eingetragen sind, wird die Ausschüttung hier berechnet.</p>
                                <?php endif; ?>
                                <?php if ($participantCount > 0 && $participantCount < (int) $current['payout_places']): ?>
                                    <p class="payout-note">Die Ausschüttung ist auf <?= $participantCount ?> besetzte Plätze begrenzt.</p>
                                <?php endif; ?>
                            </div>
                            <form class="payout-form" method="post">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="save_payout">
                                <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                <div class="field">
                                    <label for="payout-percentage">Anteil der Startgebühren</label>
                                    <div class="input-with-suffix">
                                        <input id="payout-percentage" name="payout_percentage" type="number" min="0" max="100" step="0.01" required value="<?= e(number_format((int) $current['payout_percent_basis_points'] / 100, 2, '.', '')) ?>" <?= !$canEditDetails ? 'disabled' : '' ?>>
                                        <span>%</span>
                                    </div>
                                    <span class="field-hint">Prozentualer Anteil des gesamten Gebührenbetrags, der ausgeschüttet wird.</span>
                                </div>
                                <div class="field">
                                    <label for="payout-places">Gewinnplätze</label>
                                    <select id="payout-places" name="payout_places" <?= !$canEditDetails ? 'disabled' : '' ?>>
                                        <?php for ($places = 1; $places <= 20; $places++): ?>
                                            <option value="<?= $places ?>" <?= (int) $current['payout_places'] === $places ? 'selected' : '' ?>>
                                                <?= $places ?> <?= $places === 1 ? 'Platz' : 'Plätze' ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="field-hint">Wähle, wie stark der 1. Platz gegenüber den weiteren Plätzen bevorzugt wird.</span>
                                </div>
                                <div class="field">
                                    <label for="payout-distribution">Verteilung</label>
                                    <select id="payout-distribution" name="payout_distribution" <?= !$canEditDetails ? 'disabled' : '' ?>>
                                        <option value="flat" <?= ($current['payout_distribution'] ?? 'flat') === 'flat' ? 'selected' : '' ?>>Flach abfallend</option>
                                        <option value="top_heavy" <?= ($current['payout_distribution'] ?? 'flat') === 'top_heavy' ? 'selected' : '' ?>>Kopflastig</option>
                                    </select>
                                    <span class="field-hint">
                                        <?= ($current['payout_distribution'] ?? 'flat') === 'top_heavy'
                                            ? 'Kopflastig: jeder Platz erhält ungefähr halb so viel wie der vorherige.'
                                            : 'Flach abfallend: die Beträge sinken gleichmäßig von Platz zu Platz.' ?>
                                    </span>
                                </div>
                                <?php if ($canEditDetails): ?>
                                    <button class="button button-primary" type="submit">Ausschüttung speichern</button>
                                <?php else: ?>
                                    <p class="required-note">Abgeschlossene Turniere können nicht mehr bearbeitet werden.</p>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php else: ?>
                        <p class="payout-empty">Lege ein Turnier an, um die Ausschüttung der Startgebühren festzulegen.</p>
                    <?php endif; ?>
                </section>

                <div class="workspace-grid">
                    <section class="panel setup-panel" id="turnier">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">SCHRITT 1 VON 3</p>
                                <h2><?= $current && $isLocked ? 'Turnierdaten' : 'Turnier einrichten' ?></h2>
                            </div>
                            <span class="step-badge">GRUNDLAGEN</span>
                        </div>
                        <p class="panel-intro">Lege die wichtigsten Details und den Spielmodus fest. Teilnehmer kannst du anschließend ergänzen.</p>

                        <form class="setup-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="save_tournament">
                            <?php if ($current): ?>
                                <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                            <?php endif; ?>
                            <div class="field">
                                <label for="tournament-name">Turniername</label>
                                <input id="tournament-name" name="tournament_name" type="text" maxlength="100" required placeholder="z. B. Freitagabend-Cup" value="<?= e($current['name'] ?? '') ?>" <?= !$canEditDetails ? 'disabled' : '' ?>>
                            </div>
                            <div class="form-row">
                                <div class="field">
                                    <label for="tournament-date">Datum</label>
                                    <input id="tournament-date" name="tournament_date" type="date" value="<?= e($current['event_date'] ?? '') ?>" <?= !$canEditDetails ? 'disabled' : '' ?>>
                                </div>
                                <div class="field">
                                    <label for="entry-fee">Startgebühr <span class="label-optional">PRO PERSON</span></label>
                                    <div class="input-with-suffix">
                                        <input id="entry-fee" name="entry_fee" type="number" min="0" max="999999.99" step="0.01" placeholder="0,00" value="<?= $current ? e(number_format((int) $current['entry_fee_cents'] / 100, 2, '.', '')) : '' ?>" <?= !$canEditDetails ? 'disabled' : '' ?>>
                                        <span>EUR</span>
                                    </div>
                                </div>
                            </div>
                            <div class="field">
                                <label for="tournament-mode">Turniermodus</label>
                                <select id="tournament-mode" name="tournament_mode" required <?= $isLocked ? 'disabled' : '' ?>>
                                    <option value="">Spielmodus auswählen</option>
                                    <option value="ko" <?= $formMode === 'ko' ? 'selected' : '' ?>>Einfach-KO</option>
                                    <option value="double_ko" <?= $formMode === 'double_ko' ? 'selected' : '' ?>>Doppel-KO</option>
                                    <option value="round_robin" <?= $formMode === 'round_robin' ? 'selected' : '' ?>>Rundenturnier – jeder gegen jeden</option>
                                    <option value="groups_ko" <?= $formMode === 'groups_ko' ? 'selected' : '' ?>>Gruppenphase mit anschließender K.-o.-Runde</option>
                                </select>
                                <span class="field-hint"><?= $isLocked ? 'Der Turniermodus ist nach der Auslosung festgelegt.' : 'Rundenturnier: einfache Runde, jeder spielt einmal gegen jeden. Gruppenphase: automatische Gruppenverteilung, danach K.-o.-Runde.' ?></span>
                            </div>
                            <div class="field" id="qualifiers-field" <?= $formMode === 'groups_ko' ? '' : 'hidden' ?>>
                                <label for="qualifiers-per-group">Qualifikanten je Gruppe</label>
                                <select id="qualifiers-per-group" name="qualifiers_per_group" <?= $isLocked ? 'disabled' : '' ?>>
                                    <?php for ($qualifiers = 1; $qualifiers <= 4; $qualifiers++): ?>
                                        <option value="<?= $qualifiers ?>" <?= (int) ($current['qualifiers_per_group'] ?? 2) === $qualifiers ? 'selected' : '' ?>>
                                            <?= $qualifiers ?> <?= $qualifiers === 1 ? 'Qualifikant' : 'Qualifikanten' ?> je Gruppe
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <span class="field-hint">Die Gruppen werden automatisch möglichst gleichmäßig eingeteilt. Eine Gruppe enthält mindestens einen Spieler mehr als die eingestellte Qualifikantenzahl.</span>
                            </div>
                            <div class="form-footer">
                                <span class="required-note">
                                    <?= $current && $current['status'] === 'in_progress'
                                        ? 'Name, Datum und Startgebühr sind bearbeitbar; der Modus bleibt gesperrt.'
                                        : ($current && $current['status'] === 'completed'
                                            ? 'Abgeschlossene Turniere können nicht mehr bearbeitet werden.'
                                            : 'Die Angaben können später bearbeitet werden.') ?>
                                </span>
                                <?php if ($canEditDetails): ?>
                                    <button class="button button-primary" type="submit">Speichern <span aria-hidden="true">→</span></button>
                                <?php elseif ($isLocked): ?>
                                    <a class="button button-primary" href="#teilnehmer">Weiter zum Turnier <span aria-hidden="true">→</span></a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </section>

                    <section class="panel progress-panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">DEIN FORTSCHRITT</p>
                                <h2>In drei Schritten startklar</h2>
                            </div>
                        </div>
                        <div class="progress-track" aria-label="<?= $progress ?> Prozent abgeschlossen">
                            <span style="width: <?= $progress ?>%"></span>
                        </div>
                        <ol class="steps-list">
                            <li class="step-item <?= !$current || !$current['mode'] ? 'current' : 'complete' ?>">
                                <span class="step-number">1</span>
                                <div><strong>Turnier einrichten</strong><small>Name, Datum und Spielmodus</small></div>
                                <span class="step-state"><?= $current && $current['mode'] ? '✓' : '●' ?></span>
                            </li>
                            <li class="step-item <?= $current && $current['mode'] && !$isLocked ? 'current' : '' ?> <?= $participantCount > 0 ? 'complete' : '' ?>">
                                <span class="step-number">2</span>
                                <div><strong>Spieler hinzufügen</strong><small>Teilnehmerliste erstellen</small></div>
                                <?php if ($participantCount > 0): ?><span class="step-state">✓</span><?php endif; ?>
                            </li>
                            <li class="step-item <?= $isLocked ? 'current' : '' ?> <?= $current && $current['status'] === 'completed' ? 'complete' : '' ?>">
                                <span class="step-number">3</span>
                                <div><strong>Paarungen auslosen</strong><small>Der Turnierbaum wird erstellt</small></div>
                                <?php if ($isLocked): ?><span class="step-state"><?= $current['status'] === 'completed' ? '✓' : '●' ?></span><?php endif; ?>
                            </li>
                        </ol>
                        <div class="tip-box">
                            <span class="tip-icon" aria-hidden="true">✦</span>
                            <p><strong>Gut zu wissen</strong><br>Die zufällige Auslosung verteilt die Spieler auf die erste Runde. Bei Bedarf werden automatisch Freilose eingeplant.</p>
                        </div>
                    </section>
                </div>

                <section class="panel participants-panel" id="teilnehmer">
                    <div class="panel-heading participants-heading">
                        <div>
                            <p class="eyebrow">SCHRITT 2 VON 3</p>
                            <h2>Teilnehmer</h2>
                        </div>
                        <span class="count-badge"><?= $participantCount ?> SPIELER</span>
                    </div>
                    <?php if ($current && !$isLocked): ?>
                        <form class="participant-entry" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="add_participant">
                            <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                            <div class="field">
                                <label for="player-name">Spielername</label>
                                <input id="player-name" name="player_name" type="text" maxlength="80" required placeholder="Namen eingeben">
                            </div>
                            <button class="button button-secondary" type="submit"><span aria-hidden="true">＋</span> Hinzufügen</button>
                        </form>
                        <?php if ($participantImportTournaments !== []): ?>
                            <form class="participant-entry participant-import" method="post">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="import_participants">
                                <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                <div class="field">
                                    <label for="source-tournament">Spieler aus anderem Turnier übernehmen</label>
                                    <select id="source-tournament" name="source_tournament_id" required>
                                        <option value="">Turnier auswählen</option>
                                        <?php foreach ($participantImportTournaments as $sourceTournament): ?>
                                            <option value="<?= (int) $sourceTournament['id'] ?>">
                                                <?= e($sourceTournament['name']) ?><?= $sourceTournament['event_date'] ? ' · ' . e(date('d.m.Y', strtotime($sourceTournament['event_date']))) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="button button-secondary" type="submit">Spieler importieren</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($participants): ?>
                        <ul class="participant-list">
                            <?php foreach ($participants as $participant): ?>
                                <li>
                                    <span class="participant-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($participant['name'], 0, 1))) ?></span>
                                    <span class="participant-name"><?= e($participant['name']) ?></span>
                                    <?php if ($isLocked): ?>
                                        <span class="loss-count"><?= (int) $participant['losses'] ?> <?= (int) $participant['losses'] === 1 ? 'Niederlage' : 'Niederlagen' ?></span>
                                    <?php else: ?>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="remove_participant">
                                            <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                            <input type="hidden" name="participant_id" value="<?= (int) $participant['id'] ?>">
                                            <button class="remove-button" type="submit" aria-label="Teilnehmer <?= e($participant['name']) ?> entfernen">Entfernen</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="participant-empty">
                            <span aria-hidden="true">♙</span>
                            <p><strong><?= $current ? 'Die Teilnehmerliste ist noch leer' : 'Lege zuerst ein Turnier an' ?></strong><br><?= $current ? 'Trage die Spieler einzeln ein, bevor du die Paarungen auslost.' : 'Speichere die Turnierdaten, um Spieler hinzuzufügen.' ?></p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel matches-panel" id="auslosung">
                    <div class="panel-heading matches-heading">
                        <div>
                            <p class="eyebrow">SCHRITT 3 VON 3 · SPIELPLAN</p>
                            <div class="matches-title-row">
                                <h2>Anstehende Paarungen</h2>
                                <div class="match-count-summary" aria-label="<?= $playedMatchCount ?> Spiele gespielt, <?= $remainingMatchCount ?> Spiele noch offen">
                                    <span class="match-count-played"><strong><?= $playedMatchCount ?></strong> gespielt</span>
                                    <span class="match-count-open"><strong><?= $remainingMatchCount ?></strong> noch offen</span>
                                </div>
                            </div>
                        </div>
                        <?php if ($current && $current['status'] === 'draft'): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="draw_tournament">
                                <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                <button class="button button-secondary draw-button" type="submit" <?= $participantCount < 2 || !$current['mode'] ? 'disabled' : '' ?>>Paarungen auslosen <span aria-hidden="true">↗</span></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($standings): ?>
                        <div class="standings-grid">
                            <?php foreach ($standings as $groupName => $groupStandings): ?>
                                <section class="standings-card">
                                    <div class="standings-heading">
                                        <h3><?= e($groupName === 'Rundenturnier' ? 'Turniertabelle' : $groupName) ?></h3>
                                        <?php if (($current['format'] ?? '') === 'groups_ko'): ?>
                                            <span>Top <?= (int) $current['qualifiers_per_group'] ?> qualifizieren sich</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="standings-table-wrap">
                                        <table class="standings-table">
                                            <thead>
                                                <tr>
                                                    <th scope="col">#</th>
                                                    <th scope="col">Spieler</th>
                                                    <th scope="col">Sp</th>
                                                    <th scope="col">S</th>
                                                    <th scope="col">Diff.</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($groupStandings as $place => $standing): ?>
                                                    <?php $isQualifier = ($current['format'] ?? '') === 'groups_ko' && $place < (int) $current['qualifiers_per_group']; ?>
                                                    <tr class="<?= $isQualifier ? 'standings-qualifier' : '' ?>">
                                                        <td><?= $place + 1 ?></td>
                                                        <th scope="row">
                                                            <?= e($standing['name']) ?>
                                                            <?php if ($isQualifier): ?><span class="qualifier-badge">KO</span><?php endif; ?>
                                                        </th>
                                                        <td><?= (int) $standing['played'] ?></td>
                                                        <td><?= (int) $standing['wins'] ?></td>
                                                        <td><?= (int) $standing['difference'] > 0 ? '+' : '' ?><?= (int) $standing['difference'] ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                        <p class="standings-note">Platzierung: Siege, Punktedifferenz, erzielte Punkte, danach alphabetisch.</p>
                    <?php endif; ?>
                    <?php if ($rounds): ?>
                        <?php foreach ($rounds as $roundNumber => $roundMatches): ?>
                            <div class="round-section">
                                <h3>Runde <?= (int) $roundNumber ?></h3>
                                <div class="match-grid">
                                    <?php foreach ($roundMatches as $match): ?>
                                        <article class="match-card">
                                            <div class="match-title">
                                                <span><?= e($match['bracket']) ?></span>
                                                <span><?= $match['status'] === 'pending' ? 'Offen' : ($match['status'] === 'bye' ? 'Freilos' : 'Beendet') ?></span>
                                            </div>
                                            <?php $displayLabels = $matchDisplayLabels[(int) $match['id']] ?? []; ?>
                                            <?php if (!empty($displayLabels['stage']) || !empty($displayLabels['payout'])): ?>
                                                <div class="match-classification">
                                                    <?php if (!empty($displayLabels['stage'])): ?>
                                                        <span class="match-stage-badge"><?= e($displayLabels['stage']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($displayLabels['payout'])): ?>
                                                        <span class="match-payout-badge"><?= e($displayLabels['payout']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="match-player <?= (int) $match['winner_id'] === (int) $match['player_one_id'] && $match['winner_id'] ? 'match-winner' : '' ?>">
                                                <span><?= e($match['player_one_name'] ?? 'Freilos') ?></span>
                                                <strong><?= $match['score_one'] === null ? '–' : (int) $match['score_one'] ?></strong>
                                            </div>
                                            <div class="match-player <?= (int) $match['winner_id'] === (int) $match['player_two_id'] && $match['winner_id'] ? 'match-winner' : '' ?>">
                                                <span><?= e($match['player_two_name'] ?? 'Freilos') ?></span>
                                                <strong><?= $match['score_two'] === null ? '–' : (int) $match['score_two'] ?></strong>
                                            </div>
                                            <?php if ($match['board_label']): ?>
                                                <div class="assigned-board"><span aria-hidden="true">◎</span> Dartboard <?= e($match['board_label']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($match['status'] === 'pending'): ?>
                                                <form method="post" class="board-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="assign_dartboard">
                                                    <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                                    <input type="hidden" name="match_id" value="<?= (int) $match['id'] ?>">
                                                    <label class="visually-hidden" for="board-<?= (int) $match['id'] ?>">Dartboard für Spiel <?= (int) $match['match_number'] ?></label>
                                                    <input id="board-<?= (int) $match['id'] ?>" name="board_label" type="text" maxlength="40" placeholder="z. B. 1 oder Board A" value="<?= e($match['board_label'] ?? '') ?>">
                                                    <button class="button button-secondary" type="submit"><?= $match['board_label'] ? 'Ändern' : 'Board zuweisen' ?></button>
                                                </form>
                                                <?php if ($match['board_label']): ?>
                                                    <form method="post" class="inline-form clear-board-form">
                                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="assign_dartboard">
                                                        <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                                        <input type="hidden" name="match_id" value="<?= (int) $match['id'] ?>">
                                                        <input type="hidden" name="board_label" value="">
                                                        <button class="clear-board-button" type="submit">Entfernen</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" class="result-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="save_result">
                                                    <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                                    <input type="hidden" name="match_id" value="<?= (int) $match['id'] ?>">
                                                    <label class="visually-hidden" for="score-one-<?= (int) $match['id'] ?>">Ergebnis <?= e($match['player_one_name']) ?></label>
                                                    <label class="visually-hidden" for="score-two-<?= (int) $match['id'] ?>">Ergebnis <?= e($match['player_two_name']) ?></label>
                                                    <input id="score-one-<?= (int) $match['id'] ?>" name="score_one" type="number" min="0" step="1" required aria-label="Ergebnis <?= e($match['player_one_name']) ?>">
                                                    <span>:</span>
                                                    <input id="score-two-<?= (int) $match['id'] ?>" name="score_two" type="number" min="0" step="1" required aria-label="Ergebnis <?= e($match['player_two_name']) ?>">
                                                    <button class="button button-primary" type="submit">Ergebnis speichern</button>
                                                </form>
                                            <?php elseif ($match['status'] === 'completed' && (int) $match['round_number'] >= $latestPlayedRound): ?>
                                                <form method="post" class="result-form edit-result-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="edit_result">
                                                    <input type="hidden" name="tournament_id" value="<?= (int) $current['id'] ?>">
                                                    <input type="hidden" name="match_id" value="<?= (int) $match['id'] ?>">
                                                    <label class="visually-hidden" for="edit-score-one-<?= (int) $match['id'] ?>">Neues Ergebnis <?= e($match['player_one_name']) ?></label>
                                                    <label class="visually-hidden" for="edit-score-two-<?= (int) $match['id'] ?>">Neues Ergebnis <?= e($match['player_two_name']) ?></label>
                                                    <input id="edit-score-one-<?= (int) $match['id'] ?>" name="score_one" type="number" min="0" step="1" required value="<?= (int) $match['score_one'] ?>" aria-label="Neues Ergebnis <?= e($match['player_one_name']) ?>">
                                                    <span>:</span>
                                                    <input id="edit-score-two-<?= (int) $match['id'] ?>" name="score_two" type="number" min="0" step="1" required value="<?= (int) $match['score_two'] ?>" aria-label="Neues Ergebnis <?= e($match['player_two_name']) ?>">
                                                    <button class="button button-secondary" type="submit">Ergebnis ändern</button>
                                                </form>
                                                <p class="result-edit-note">Nur möglich, solange keine spätere Runde gespielt wurde.</p>
                                            <?php elseif ($match['status'] === 'completed'): ?>
                                                <p class="result-edit-note">Nicht änderbar: Eine spätere Runde wurde bereits gespielt.</p>
                                            <?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($upcomingPreview): ?>
                            <div class="round-section upcoming-preview">
                                <h3>Runde <?= (int) $upcomingPreview['round_number'] ?> <span>· Vorschau</span></h3>
                                <p class="preview-note">Bekannte Spieler sind bereits zugeordnet. Offene Plätze hängen vom Ergebnis der laufenden Spiele ab.</p>
                                <div class="match-grid">
                                    <?php foreach ($upcomingPreview['matches'] as $previewMatch): ?>
                                        <?php
                                        $playerOneUndecided = str_starts_with($previewMatch['player_one_name'], 'Sieger aus')
                                            || str_starts_with($previewMatch['player_one_name'], 'Verlierer aus');
                                        $playerTwoUndecided = str_starts_with($previewMatch['player_two_name'], 'Sieger aus')
                                            || str_starts_with($previewMatch['player_two_name'], 'Verlierer aus');
                                        ?>
                                        <article class="match-card preview-match">
                                            <div class="match-title">
                                                <span><?= e($previewMatch['bracket']) ?></span>
                                                <span>Vorschau</span>
                                            </div>
                                            <?php if (!empty($previewMatch['stage_label']) || !empty($previewMatch['payout_label'])): ?>
                                                <div class="match-classification">
                                                    <?php if (!empty($previewMatch['stage_label'])): ?>
                                                        <span class="match-stage-badge"><?= e($previewMatch['stage_label']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($previewMatch['payout_label'])): ?>
                                                        <span class="match-payout-badge"><?= e($previewMatch['payout_label']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="match-player <?= $playerOneUndecided ? 'match-undecided' : '' ?>">
                                                <span><?= e($previewMatch['player_one_name']) ?></span>
                                                <strong>–</strong>
                                            </div>
                                            <div class="match-player <?= $playerTwoUndecided ? 'match-undecided' : '' ?>">
                                                <span><?= e($previewMatch['player_two_name']) ?></span>
                                                <strong>–</strong>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="empty-illustration" aria-hidden="true">
                                <span class="dart dart-one"></span><span class="dart dart-two"></span><span class="dartboard">◎</span>
                            </span>
                            <strong>Der Spielplan wartet auf dich</strong>
                            <p>Richte ein Turnier ein, füge Teilnehmer hinzu und lose die ersten Paarungen aus.</p>
                            <a class="button button-secondary" href="#turnier">Turnier jetzt einrichten</a>
                        </div>
                    <?php endif; ?>
                    <?php if ($winner): ?>
                        <div class="winner-banner"><span aria-hidden="true">🏆</span> Turniersieger: <strong><?= e($winner) ?></strong></div>
                    <?php endif; ?>
                </section>

                <footer class="page-footer">DARTBRACKET <span>·</span> TURNIERVERWALTUNG</footer>
            </div>
        </main>
    </div>
    <script>
        const tournamentMode = document.getElementById('tournament-mode');
        const qualifiersField = document.getElementById('qualifiers-field');
        if (tournamentMode && qualifiersField) {
            const updateQualifiersVisibility = () => {
                qualifiersField.hidden = tournamentMode.value !== 'groups_ko';
            };
            tournamentMode.addEventListener('change', updateQualifiersVisibility);
            updateQualifiersVisibility();
        }
    </script>
</body>
</html>