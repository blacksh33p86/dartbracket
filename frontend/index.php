<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/tournament.php';

startSecureSession();
$db = database();

if (currentUser($db) === null) {
    if (isset($_GET['data'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private, max-age=0');
        echo json_encode(['error' => 'Die Anmeldung ist abgelaufen. Bitte melde dich erneut an.']);
        exit;
    }

    if (isset($_GET['asset'])) {
        http_response_code(401);
        exit;
    }

    $loginPath = defined('DART_SCREEN_BACKEND_ROUTE') ? 'login.php' : '../backend/login.php';
    header('Location: ' . $loginPath, true, 303);
    exit;
}

if (isset($_GET['asset'])) {
    $assets = [
        'css' => ['screen.css', 'text/css; charset=utf-8'],
        'js' => ['screen.js', 'text/javascript; charset=utf-8'],
    ];
    $asset = $assets[(string) $_GET['asset']] ?? null;

    if ($asset === null) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $asset[1]);
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile(__DIR__ . '/' . $asset[0]);
    exit;
}

$requestedTournamentId = filter_var($_GET['tournament_id'] ?? null, FILTER_VALIDATE_INT);
$tournamentId = $requestedTournamentId !== false && $requestedTournamentId !== null
    ? $requestedTournamentId
    : filter_var($_SESSION['active_tournament_id'] ?? null, FILTER_VALIDATE_INT);
$tournamentId = $tournamentId === false || $tournamentId === null ? null : (int) $tournamentId;

if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');

    try {
        $tournament = $tournamentId !== null ? tournamentById($db, $tournamentId) : currentTournament($db);

        if ($tournament !== null && (int) $tournament['archived'] === 1) {
            $tournament = null;
        }

        if ($tournament === null) {
            echo json_encode([
                'tournament' => null,
                'rounds' => [],
                'upcoming_preview' => [],
                'standings' => [],
                'counts' => ['played' => 0, 'remaining' => 0, 'pending_now' => 0],
                'updated_at' => gmdate(DATE_ATOM),
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $tournamentId = (int) $tournament['id'];
        $matches = matchesForTournament($db, $tournamentId);
        $participants = participantsForTournament($db, $tournamentId);
        $standings = standingsForTournament($db, $tournament);
        $payout = payoutPlacesWithWinners($tournament, count($participants), $matches, $standings);
        $displayLabels = matchDisplayLabels($matches, $tournament);
        $rounds = [];

        foreach ($matches as $match) {
            $matchId = (int) $match['id'];
            $match['stage_label'] = $displayLabels[$matchId]['stage'] ?? null;
            $match['payout_label'] = $displayLabels[$matchId]['payout'] ?? null;
            $rounds[(int) $match['round_number']][] = $match;
        }

        echo json_encode([
            'tournament' => [
                'id' => $tournamentId,
                'name' => $tournament['name'],
                'event_date' => $tournament['event_date'],
                'status' => $tournament['status'],
                'format' => $tournament['format'] ?? 'knockout',
                'qualifiers_per_group' => (int) ($tournament['qualifiers_per_group'] ?? 2),
                'participant_count' => count($participants),
            ],
            'rounds' => array_values(array_map(
                static fn (int|string $number, array $items): array => [
                    'number' => (int) $number,
                    'matches' => $items,
                ],
                array_keys($rounds),
                array_values($rounds)
            )),
            'upcoming_preview' => upcomingMatchPreviews($db, $tournament, $matches),
            'standings' => $standings,
            'payout' => [
                'total_cents' => $payout['total_cents'],
                'pool_cents' => $payout['pool_cents'],
                'percentage_basis_points' => (int) $tournament['payout_percent_basis_points'],
                'places' => $payout['places'],
            ],
            'counts' => [
                'played' => count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'completed')),
                'remaining' => totalMatchCountForTournament($tournament, count($participants), $matches),
                'pending_now' => count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'pending')),
            ],
            'updated_at' => gmdate(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $exception) {
        error_log('Turnierverwaltung Beameransicht: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Die Turnierdaten konnten nicht geladen werden.']);
    }

    exit;
}

$screenTournamentId = $tournamentId;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#090b12">
    <title>Beameransicht | DartBracket</title>
    <link rel="stylesheet" href="?asset=css">
</head>
<body class="screen-page">
    <main class="screen-shell" id="screen-app" aria-live="polite" data-tournament-id="<?= $screenTournamentId ?? '' ?>">
        <div class="screen-loading">Turnierdaten werden geladen …</div>
    </main>
    <script src="?asset=js" defer></script>
</body>
</html>