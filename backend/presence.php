<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Methode nicht erlaubt.']);
    exit;
}

startSecureSession();
$db = database();
$currentUser = currentUser($db);

if ($currentUser === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Die Sitzung ist nicht angemeldet.']);
    exit;
}

try {
    validateCsrfToken($_POST);
} catch (DomainException $exception) {
    http_response_code(403);
    echo json_encode(['error' => $exception->getMessage()]);
    exit;
}

session_write_close();
echo json_encode(['users' => activeUsers($db)], JSON_THROW_ON_ERROR);
