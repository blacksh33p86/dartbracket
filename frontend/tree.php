<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/tournament.php';

startSecureSession();
$db = database();

if (currentUser($db) === null) {
    if (isset($_GET['asset'])) {
        http_response_code(401);
        exit;
    }

    $loginPath = defined('DART_TREE_BACKEND_ROUTE') ? 'login.php' : '../backend/login.php';
    header('Location: ' . $loginPath, true, 303);
    exit;
}

$asset = (string) ($_GET['asset'] ?? '');
if ($asset !== '') {
    $assets = [
        'css' => ['tree.css', 'text/css; charset=utf-8'],
        'js' => ['tree.js', 'text/javascript; charset=utf-8'],
    ];
    $file = $assets[$asset] ?? null;

    if ($file === null) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $file[1]);
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile(__DIR__ . '/' . $file[0]);
    exit;
}

$requestedTournamentId = filter_var($_GET['tournament_id'] ?? null, FILTER_VALIDATE_INT);
$tournamentId = $requestedTournamentId !== false && $requestedTournamentId !== null
    ? $requestedTournamentId
    : filter_var($_SESSION['active_tournament_id'] ?? null, FILTER_VALIDATE_INT);
$tournamentId = $tournamentId === false || $tournamentId === null ? null : (int) $tournamentId;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#090b12">
    <title>Turnierbaum | DartBracket</title>
    <link rel="stylesheet" href="?asset=css">
</head>
<body class="tree-page">
    <main class="tree-shell" id="tree-app" data-tournament-id="<?= $tournamentId ?? '' ?>">
        <div class="tree-loading">Turnierbaum wird geladen …</div>
    </main>
    <script src="?asset=js" defer></script>
</body>
</html>
