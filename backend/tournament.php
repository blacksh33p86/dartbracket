<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function currentTournament(PDO $db): ?array
{
    $statement = $db->query('SELECT * FROM tournaments WHERE archived = 0 ORDER BY id DESC LIMIT 1');
    $tournament = $statement->fetch();

    return $tournament ?: null;
}

function tournaments(PDO $db): array
{
    return $db->query(
        'SELECT id, name, event_date, status
         FROM tournaments
         WHERE archived = 0
         ORDER BY event_date DESC, id DESC'
    )->fetchAll();
}

function archivedTournaments(PDO $db): array
{
    return $db->query(
        'SELECT id, name, event_date, status, archived
         FROM tournaments
         WHERE archived = 1
         ORDER BY event_date DESC, id DESC'
    )->fetchAll();
}

function tournamentById(PDO $db, int $tournamentId): ?array
{
    $statement = $db->prepare('SELECT * FROM tournaments WHERE id = :id');
    $statement->execute(['id' => $tournamentId]);
    $tournament = $statement->fetch();

    return $tournament ?: null;
}

function createTournament(PDO $db): int
{
    $db->exec("INSERT INTO tournaments (name) VALUES ('Neues Turnier')");

    return (int) $db->lastInsertId();
}

function deleteTournament(PDO $db, int $tournamentId): string
{
    $tournament = tournamentById($db, $tournamentId);

    if ($tournament === null) {
        throw new DomainException('Das Turnier wurde nicht gefunden oder bereits gelöscht.');
    }

    $statement = $db->prepare('DELETE FROM tournaments WHERE id = :id');
    $statement->execute(['id' => $tournamentId]);

    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Das Turnier konnte nicht gelöscht werden.');
    }

    return (string) $tournament['name'];
}

function deleteArchivedTournament(PDO $db, int $tournamentId): string
{
    $statement = $db->prepare('SELECT name FROM tournaments WHERE id = :id AND archived = 1');
    $statement->execute(['id' => $tournamentId]);
    $name = $statement->fetchColumn();

    if ($name === false) {
        throw new DomainException('Das archivierte Turnier wurde nicht gefunden.');
    }

    $delete = $db->prepare('DELETE FROM tournaments WHERE id = :id AND archived = 1');
    $delete->execute(['id' => $tournamentId]);

    if ($delete->rowCount() !== 1) {
        throw new DomainException('Das archivierte Turnier wurde nicht gefunden.');
    }

    return (string) $name;
}

function archiveTournament(PDO $db, int $tournamentId): string
{
    $tournament = tournamentById($db, $tournamentId);

    if ($tournament === null || (int) $tournament['archived'] === 1) {
        throw new DomainException('Das Turnier wurde nicht gefunden oder ist bereits archiviert.');
    }

    $statement = $db->prepare(
        'UPDATE tournaments
         SET archived = 1, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND archived = 0'
    );
    $statement->execute(['id' => $tournamentId]);

    if ($statement->rowCount() !== 1) {
        throw new DomainException('Das Turnier wurde zwischenzeitlich archiviert. Bitte lade die Seite neu.');
    }

    return (string) $tournament['name'];
}

function restoreTournament(PDO $db, int $tournamentId): string
{
    $tournament = tournamentById($db, $tournamentId);

    if ($tournament === null || (int) $tournament['archived'] !== 1) {
        throw new DomainException('Das archivierte Turnier wurde nicht gefunden.');
    }

    $statement = $db->prepare(
        'UPDATE tournaments
         SET archived = 0, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND archived = 1'
    );
    $statement->execute(['id' => $tournamentId]);

    if ($statement->rowCount() !== 1) {
        throw new DomainException('Das Turnier wurde zwischenzeitlich wiederhergestellt. Bitte lade die Seite neu.');
    }

    return (string) $tournament['name'];
}

function saveTournament(PDO $db, array $input, ?array $current): int
{
    $name = trim((string) ($input['tournament_name'] ?? ''));
    $eventDate = trim((string) ($input['tournament_date'] ?? ''));
    $feeInput = str_replace(',', '.', trim((string) ($input['entry_fee'] ?? '0')));
    $selectedMode = (string) ($input['tournament_mode'] ?? '');
    $mode = $selectedMode === 'double_ko' ? 'double_ko' : 'ko';
    $format = match ($selectedMode) {
        'ko', 'double_ko' => 'knockout',
        'round_robin' => 'round_robin',
        'groups_ko' => 'groups_ko',
        default => '',
    };
    $qualifiersPerGroup = filter_var($input['qualifiers_per_group'] ?? '2', FILTER_VALIDATE_INT);

    if ($feeInput === '') {
        $feeInput = '0';
    }

    if ($name === '' || mb_strlen($name) > 100) {
        throw new DomainException('Bitte gib einen Turniernamen mit höchstens 100 Zeichen ein.');
    }

    if ($eventDate !== '' && !validDate($eventDate)) {
        throw new DomainException('Bitte gib ein gültiges Turnierdatum ein.');
    }

    if (!is_numeric($feeInput) || (float) $feeInput < 0 || (float) $feeInput > 999999.99) {
        throw new DomainException('Die Startgebühr muss zwischen 0 und 999.999,99 EUR liegen.');
    }

    $feeCents = (int) round((float) $feeInput * 100);

    if ($current !== null) {
        if ($current['status'] === 'completed') {
            throw new DomainException('Ein abgeschlossenes Turnier kann nicht mehr geändert werden.');
        }

        if ($current['status'] === 'in_progress') {
            $statement = $db->prepare(
                'UPDATE tournaments
                 SET name = :name, event_date = :event_date, entry_fee_cents = :fee,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = \'in_progress\''
            );
            $statement->execute([
                'name' => $name,
                'event_date' => $eventDate !== '' ? $eventDate : null,
                'fee' => $feeCents,
                'id' => $current['id'],
            ]);

            return (int) $current['id'];
        }

        if ($format === '' || $qualifiersPerGroup === false || $qualifiersPerGroup < 1 || $qualifiersPerGroup > 4) {
            throw new DomainException('Bitte wähle einen gültigen Turniermodus und eine Qualifikantenzahl zwischen 1 und 4.');
        }

        $statement = $db->prepare(
            'UPDATE tournaments
             SET name = :name, event_date = :event_date, entry_fee_cents = :fee,
                 mode = :mode, format = :format, qualifiers_per_group = :qualifiers,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'name' => $name,
            'event_date' => $eventDate !== '' ? $eventDate : null,
            'fee' => $feeCents,
            'mode' => $mode,
            'format' => $format,
            'qualifiers' => $qualifiersPerGroup,
            'id' => $current['id'],
        ]);

        return (int) $current['id'];
    }

    if ($format === '' || $qualifiersPerGroup === false || $qualifiersPerGroup < 1 || $qualifiersPerGroup > 4) {
        throw new DomainException('Bitte wähle einen gültigen Turniermodus und eine Qualifikantenzahl zwischen 1 und 4.');
    }

    $statement = $db->prepare(
        'INSERT INTO tournaments (name, event_date, entry_fee_cents, mode, format, qualifiers_per_group)
         VALUES (:name, :event_date, :fee, :mode, :format, :qualifiers)'
    );
    $statement->execute([
        'name' => $name,
        'event_date' => $eventDate !== '' ? $eventDate : null,
        'fee' => $feeCents,
        'mode' => $mode,
        'format' => $format,
        'qualifiers' => $qualifiersPerGroup,
    ]);

    return (int) $db->lastInsertId();
}

function savePayoutSettings(PDO $db, int $tournamentId, array $input): void
{
    $tournament = tournamentById($db, $tournamentId);

    if ($tournament === null || $tournament['status'] === 'completed') {
        throw new DomainException('Die Ausschüttung kann nur bei einem offenen Turnier geändert werden.');
    }

    $percentageInput = str_replace(',', '.', trim((string) ($input['payout_percentage'] ?? '')));
    $places = filter_var($input['payout_places'] ?? null, FILTER_VALIDATE_INT);
    $distribution = (string) ($input['payout_distribution'] ?? '');

    if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $percentageInput)
        || (float) $percentageInput < 0
        || (float) $percentageInput > 100) {
        throw new DomainException('Die Ausschüttungsquote muss zwischen 0 und 100 Prozent liegen.');
    }

    if ($places === false || $places < 1 || $places > 20) {
        throw new DomainException('Die Anzahl der Gewinnplätze muss zwischen 1 und 20 liegen.');
    }

    if (!in_array($distribution, ['flat', 'top_heavy'], true)) {
        throw new DomainException('Bitte wähle eine gültige Ausschüttungsart.');
    }

    $basisPoints = (int) round((float) $percentageInput * 100);
    $statement = $db->prepare(
        'UPDATE tournaments
         SET payout_percent_basis_points = :percentage, payout_places = :places,
             payout_distribution = :distribution,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status <> \'completed\''
    );
    $statement->execute([
        'percentage' => $basisPoints,
        'places' => $places,
        'distribution' => $distribution,
        'id' => $tournamentId,
    ]);
}

function payoutForTournament(?array $tournament, int $participantCount): array
{
    if ($tournament === null) {
        return ['total_cents' => 0, 'pool_cents' => 0, 'places' => []];
    }

    $totalCents = (int) $tournament['entry_fee_cents'] * $participantCount;
    $percentage = (int) $tournament['payout_percent_basis_points'];
    $poolCents = intdiv($totalCents, 10000) * $percentage
        + intdiv(($totalCents % 10000) * $percentage + 5000, 10000);
    $numberOfPlaces = min((int) $tournament['payout_places'], $participantCount);
    $distribution = (string) ($tournament['payout_distribution'] ?? 'flat');
    $weights = [];
    for ($place = 1; $place <= $numberOfPlaces; $place++) {
        $weights[] = $distribution === 'top_heavy'
            ? 1 << ($numberOfPlaces - $place)
            : $numberOfPlaces - $place + 1;
    }
    $totalWeight = array_sum($weights);
    $baseCents = $totalWeight > 0 ? intdiv($poolCents, $totalWeight) : 0;
    $remainingCents = $totalWeight > 0 ? $poolCents % $totalWeight : 0;
    $remainders = [];
    $places = [];

    foreach ($weights as $index => $weight) {
        $remainderNumerator = $remainingCents * $weight;
        $places[] = [
            'place' => $index + 1,
            'amount_cents' => $baseCents * $weight + intdiv($remainderNumerator, $totalWeight),
        ];
        $remainders[] = [
            'index' => $index,
            'remainder' => $remainderNumerator % $totalWeight,
        ];
    }

    $allocatedCents = array_sum(array_column($places, 'amount_cents'));
    usort($remainders, static fn (array $left, array $right): int =>
        $right['remainder'] <=> $left['remainder'] ?: $left['index'] <=> $right['index']
    );

    for ($cent = 0; $cent < $poolCents - $allocatedCents; $cent++) {
        $places[$remainders[$cent]['index']]['amount_cents']++;
    }

    return [
        'total_cents' => $totalCents,
        'pool_cents' => $poolCents,
        'places' => $places,
    ];
}

function validDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function addParticipant(PDO $db, int $tournamentId, string $name): void
{
    $name = trim($name);

    if ($name === '' || mb_strlen($name) > 80) {
        throw new DomainException('Bitte gib einen Spielernamen mit höchstens 80 Zeichen ein.');
    }

    $tournament = tournamentById($db, $tournamentId);

    if ($tournament === null || $tournament['status'] !== 'draft') {
        throw new DomainException('Teilnehmer können nur vor der Auslosung geändert werden.');
    }

    try {
        $statement = $db->prepare('INSERT INTO participants (tournament_id, name) VALUES (:tournament_id, :name)');
        $statement->execute(['tournament_id' => $tournamentId, 'name' => $name]);
    } catch (PDOException $exception) {
        if (
            str_contains($exception->getMessage(), 'UNIQUE constraint failed')
            || ($exception->getCode() === '23000'
                && str_contains(strtolower($exception->getMessage()), 'participants_tournament_name_unique'))
        ) {
            throw new DomainException('Dieser Name steht bereits auf der Teilnehmerliste.');
        }

        throw $exception;
    }
}

function removeParticipant(PDO $db, int $tournamentId, int $participantId): void
{
    $tournament = tournamentById($db, $tournamentId);

    if ($tournament === null || $tournament['status'] !== 'draft') {
        throw new DomainException('Teilnehmer können nur vor der Auslosung entfernt werden.');
    }

    $statement = $db->prepare('DELETE FROM participants WHERE id = :id AND tournament_id = :tournament_id');
    $statement->execute(['id' => $participantId, 'tournament_id' => $tournamentId]);

    if ($statement->rowCount() === 0) {
        throw new DomainException('Der Teilnehmer wurde nicht gefunden.');
    }
}

function importParticipants(PDO $db, int $tournamentId, int $sourceTournamentId): array
{
    if ($sourceTournamentId < 1 || $sourceTournamentId === $tournamentId) {
        throw new DomainException('Bitte wähle ein anderes Turnier als Quelle aus.');
    }

    $db->beginTransaction();

    try {
        $statement = $db->prepare(
            'SELECT id, status, archived
             FROM tournaments
             WHERE id IN (:tournament_id, :source_tournament_id)
             ORDER BY id
             FOR UPDATE'
        );
        $statement->execute([
            'tournament_id' => $tournamentId,
            'source_tournament_id' => $sourceTournamentId,
        ]);
        $lockedTournaments = [];
        foreach ($statement->fetchAll() as $tournament) {
            $lockedTournaments[(int) $tournament['id']] = $tournament;
        }

        $target = $lockedTournaments[$tournamentId] ?? null;
        if ($target === null || (int) $target['archived'] === 1 || $target['status'] !== 'draft') {
            throw new DomainException('Teilnehmer können nur in ein nicht archiviertes Turnier vor der Auslosung importiert werden.');
        }

        $source = $lockedTournaments[$sourceTournamentId] ?? null;
        if ($source === null || (int) $source['archived'] === 1) {
            throw new DomainException('Das ausgewählte Quellturnier wurde nicht gefunden oder ist archiviert.');
        }

        $statement = $db->prepare(
            'SELECT name FROM participants WHERE tournament_id = :tournament_id ORDER BY name, id'
        );
        $statement->execute(['tournament_id' => $sourceTournamentId]);
        $sourceNames = $statement->fetchAll(PDO::FETCH_COLUMN);

        $insert = $db->prepare(
            'INSERT INTO participants (tournament_id, name) VALUES (:tournament_id, :name)'
        );
        $importedCount = 0;
        $skippedCount = 0;

        foreach ($sourceNames as $name) {
            try {
                $insert->execute(['tournament_id' => $tournamentId, 'name' => $name]);
                $importedCount++;
            } catch (PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1062) {
                    throw $exception;
                }

                $skippedCount++;
            }
        }

        $db->commit();

        return ['imported' => $importedCount, 'skipped' => $skippedCount];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function drawTournament(PDO $db, int $tournamentId): void
{
    $tournament = tournamentById($db, $tournamentId);

    if ($tournament === null || $tournament['status'] !== 'draft') {
        throw new DomainException('Dieses Turnier kann nicht ausgelost werden.');
    }

    $statement = $db->prepare('SELECT id FROM participants WHERE tournament_id = :id ORDER BY id');
    $statement->execute(['id' => $tournamentId]);
    $participants = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

    if (count($participants) < 2) {
        throw new DomainException('Für die Auslosung werden mindestens zwei Teilnehmer benötigt.');
    }

    if ($tournament['mode'] === null) {
        throw new DomainException('Lege zuerst den Turniermodus fest.');
    }

    $format = (string) ($tournament['format'] ?? 'knockout');
    $qualifiersPerGroup = (int) ($tournament['qualifiers_per_group'] ?? 2);

    if ($format === 'groups_ko' && count($participants) < 2 * ($qualifiersPerGroup + 1)) {
        throw new DomainException(sprintf(
            'Für mindestens zwei Gruppen mit jeweils %s werden mindestens %d Teilnehmer benötigt.',
            $qualifiersPerGroup === 1 ? '1 Qualifikant' : $qualifiersPerGroup . ' Qualifikanten',
            2 * ($qualifiersPerGroup + 1)
        ));
    }

    shuffle($participants);
    $db->beginTransaction();

    try {
        $statement = $db->prepare('UPDATE tournaments SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute(['status' => 'in_progress', 'id' => $tournamentId]);
        if ($format === 'round_robin') {
            createRoundRobinMatches($db, $tournamentId, [
                ['bracket' => 'Rundenturnier', 'players' => $participants],
            ]);
        } elseif ($format === 'groups_ko') {
            $groupSizes = groupSizesForTournament(count($participants), $qualifiersPerGroup);
            $groups = array_fill(0, count($groupSizes), []);
            foreach ($participants as $index => $participantId) {
                $groups[$index % count($groups)][] = $participantId;
            }

            $brackets = [];
            foreach ($groups as $index => $players) {
                $brackets[] = [
                    'bracket' => 'Gruppe ' . groupLabel($index),
                    'players' => $players,
                ];
            }
            createRoundRobinMatches($db, $tournamentId, $brackets);
        } else {
            createRoundMatches($db, $tournamentId, 1, [['bracket' => 'Sieger-Runde', 'players' => $participants]]);
            advanceTournament($db, $tournamentId);
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}

function groupSizesForTournament(int $participantCount, int $qualifiersPerGroup): array
{
    if ($participantCount === 0) {
        return [];
    }

    $groupCount = max(2, (int) ceil($participantCount / ($qualifiersPerGroup + 3)));
    $sizes = array_fill(0, $groupCount, intdiv($participantCount, $groupCount));

    for ($index = 0; $index < $participantCount % $groupCount; $index++) {
        $sizes[$index]++;
    }

    return $sizes;
}

function totalMatchCountForTournament(?array $tournament, int $participantCount, array $matches): int
{
    if ($tournament === null
        || $participantCount < 2
        || $tournament['mode'] === null
        || $tournament['status'] === 'completed') {
        return 0;
    }

    $format = (string) ($tournament['format'] ?? 'knockout');
    if ($format === 'round_robin') {
        $totalMatches = intdiv($participantCount * ($participantCount - 1), 2);
    } elseif ($format === 'groups_ko') {
        $qualifiersPerGroup = (int) $tournament['qualifiers_per_group'];
        if ($participantCount < 2 * ($qualifiersPerGroup + 1)) {
            return 0;
        }

        $groupSizes = groupSizesForTournament($participantCount, $qualifiersPerGroup);
        $groupMatches = array_sum(array_map(
            static fn (int $size): int => intdiv($size * ($size - 1), 2),
            $groupSizes
        ));
        $qualifierCount = count($groupSizes) * $qualifiersPerGroup;
        $totalMatches = $groupMatches + $qualifierCount - 1;
    } elseif ($tournament['mode'] === 'double_ko') {
        $totalMatches = 2 * $participantCount - 2;
        if (array_filter(
            $matches,
            static fn (array $match): bool => $match['bracket'] === 'Finale (Rückspiel)'
                || ($match['bracket'] === 'Finale' && $match['status'] === 'pending')
        )) {
            $totalMatches++;
        }
    } else {
        $totalMatches = $participantCount - 1;
    }

    return max(0, $totalMatches - count(array_filter(
        $matches,
        static fn (array $match): bool => $match['status'] === 'completed'
    )));
}

function lockTournamentForResult(PDO $db, int $tournamentId): array
{
    $statement = $db->prepare('SELECT * FROM tournaments WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $tournamentId]);
    $tournament = $statement->fetch();

    if (!$tournament) {
        throw new DomainException('Das Turnier wurde nicht gefunden.');
    }

    return $tournament;
}

function recordResult(PDO $db, int $tournamentId, int $matchId, int $scoreOne, int $scoreTwo): void
{
    validateMatchScore($scoreOne, $scoreTwo);

    $db->beginTransaction();

    try {
        lockTournamentForResult($db, $tournamentId);
        $statement = $db->prepare(
            "SELECT * FROM matches
             WHERE id = :match_id AND tournament_id = :tournament_id AND status = 'pending'"
        );
        $statement->execute(['match_id' => $matchId, 'tournament_id' => $tournamentId]);
        $match = $statement->fetch();

        if (!$match || $match['player_one_id'] === null || $match['player_two_id'] === null) {
            throw new DomainException('Dieses Spiel ist nicht offen für ein Ergebnis.');
        }

        $winnerId = $scoreOne > $scoreTwo ? (int) $match['player_one_id'] : (int) $match['player_two_id'];
        $loserId = $winnerId === (int) $match['player_one_id']
            ? (int) $match['player_two_id']
            : (int) $match['player_one_id'];
        $statement = $db->prepare(
            "UPDATE matches
             SET score_one = :score_one, score_two = :score_two, winner_id = :winner_id, status = 'completed'
             WHERE id = :id AND status = 'pending'"
        );
        $statement->execute([
            'score_one' => $scoreOne,
            'score_two' => $scoreTwo,
            'winner_id' => $winnerId,
            'id' => $matchId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new DomainException('Das Ergebnis konnte nicht gespeichert werden.');
        }

        $isRoundRobinMatch = (string) ($match['bracket'] ?? '') === 'Rundenturnier';
        $isGroupStageMatch = str_starts_with((string) ($match['bracket'] ?? ''), 'Gruppe ');
        if (!$isRoundRobinMatch && !$isGroupStageMatch) {
            $statement = $db->prepare('UPDATE participants SET losses = losses + 1 WHERE id = :id');
            $statement->execute(['id' => $loserId]);
        }
        advanceTournament($db, $tournamentId);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}

function editResult(PDO $db, int $tournamentId, int $matchId, int $scoreOne, int $scoreTwo): void
{
    validateMatchScore($scoreOne, $scoreTwo);
    $db->beginTransaction();

    try {
        $tournament = lockTournamentForResult($db, $tournamentId);
        $statement = $db->prepare(
            "SELECT * FROM matches
             WHERE id = :match_id AND tournament_id = :tournament_id AND status = 'completed'
             FOR UPDATE"
        );
        $statement->execute(['match_id' => $matchId, 'tournament_id' => $tournamentId]);
        $match = $statement->fetch();

        if (!$match || $match['player_one_id'] === null || $match['player_two_id'] === null) {
            throw new DomainException('Dieses beendete Spiel kann nicht geändert werden.');
        }

        $laterPlayed = $db->prepare(
            "SELECT COUNT(*) FROM matches
             WHERE tournament_id = :tournament_id
               AND round_number > :round_number
               AND status = 'completed'"
        );
        $laterPlayed->execute([
            'tournament_id' => $tournamentId,
            'round_number' => $match['round_number'],
        ]);
        if ((int) $laterPlayed->fetchColumn() > 0) {
            throw new DomainException('Das Ergebnis kann nicht geändert werden, weil eine spätere Runde bereits gespielt wurde.');
        }

        if ((int) $tournament['archived'] === 1) {
            throw new DomainException('Das Turnier wurde nicht gefunden oder ist archiviert.');
        }

        $winnerId = $scoreOne > $scoreTwo
            ? (int) $match['player_one_id']
            : (int) $match['player_two_id'];
        $oldWinnerId = (int) $match['winner_id'];
        $winnerChanged = $winnerId !== $oldWinnerId;
        $format = (string) ($tournament['format'] ?? 'knockout');
        $isGroupStageMatch = str_starts_with((string) $match['bracket'], 'Gruppe ');
        $hasKnockoutMatches = false;

        if ($format === 'groups_ko' && $isGroupStageMatch) {
            $knockoutCount = $db->prepare(
                "SELECT COUNT(*) FROM matches
                 WHERE tournament_id = :tournament_id AND bracket = 'K.-o.-Runde'"
            );
            $knockoutCount->execute(['tournament_id' => $tournamentId]);
            $hasKnockoutMatches = (int) $knockoutCount->fetchColumn() > 0;
        }

        $rebuildProgression = $format !== 'round_robin'
            && ($winnerChanged || ($isGroupStageMatch && $hasKnockoutMatches));

        if ($rebuildProgression) {
            $qualifierIds = [];
            if ($format === 'groups_ko' && !$isGroupStageMatch) {
                $firstKnockoutRound = $db->prepare(
                    "SELECT player_one_id, player_two_id
                     FROM matches
                     WHERE tournament_id = :tournament_id
                       AND bracket = 'K.-o.-Runde'
                       AND round_number = (
                           SELECT MIN(round_number)
                           FROM matches
                           WHERE tournament_id = :first_round_tournament_id
                             AND bracket = 'K.-o.-Runde'
                       )
                     ORDER BY match_number"
                );
                $firstKnockoutRound->execute([
                    'tournament_id' => $tournamentId,
                    'first_round_tournament_id' => $tournamentId,
                ]);
                foreach ($firstKnockoutRound->fetchAll() as $knockoutMatch) {
                    foreach (['player_one_id', 'player_two_id'] as $column) {
                        if ($knockoutMatch[$column] !== null) {
                            $qualifierIds[(int) $knockoutMatch[$column]] = true;
                        }
                    }
                }
                if ($qualifierIds === []) {
                    throw new RuntimeException('Die qualifizierten Spieler der K.-o.-Runde konnten nicht ermittelt werden.');
                }
            }

            if ($format === 'groups_ko' && $isGroupStageMatch) {
                $laterMatches = $db->prepare(
                    "SELECT id, player_one_id, status
                     FROM matches
                     WHERE tournament_id = :tournament_id AND bracket = 'K.-o.-Runde'"
                );
                $laterMatches->execute(['tournament_id' => $tournamentId]);
            } else {
                $laterMatches = $db->prepare(
                    'SELECT id, player_one_id, status
                     FROM matches
                     WHERE tournament_id = :tournament_id
                       AND round_number > :round_number
                       AND status <> \'completed\''
                );
                $laterMatches->execute([
                    'tournament_id' => $tournamentId,
                    'round_number' => $match['round_number'],
                ]);
            }

            $matchesToRemove = $laterMatches->fetchAll();
            foreach ($matchesToRemove as $laterMatch) {
                if ($laterMatch['status'] === 'bye' && $laterMatch['player_one_id'] !== null) {
                    $decrementBye = $db->prepare(
                        'UPDATE participants
                         SET bye_count = CASE WHEN bye_count > 0 THEN bye_count - 1 ELSE 0 END
                         WHERE id = :id'
                    );
                    $decrementBye->execute(['id' => $laterMatch['player_one_id']]);
                }
                $deleteMatch = $db->prepare('DELETE FROM matches WHERE id = :id');
                $deleteMatch->execute(['id' => $laterMatch['id']]);
            }
        }

        $update = $db->prepare(
            "UPDATE matches
             SET score_one = :score_one, score_two = :score_two, winner_id = :winner_id
             WHERE id = :id AND tournament_id = :tournament_id AND status = 'completed'"
        );
        $update->execute([
            'score_one' => $scoreOne,
            'score_two' => $scoreTwo,
            'winner_id' => $winnerId,
            'id' => $matchId,
            'tournament_id' => $tournamentId,
        ]);

        if ($rebuildProgression) {
            $reset = $db->prepare('UPDATE participants SET losses = 0 WHERE tournament_id = :id');
            $reset->execute(['id' => $tournamentId]);

            if ($format === 'groups_ko' && !$isGroupStageMatch) {
                $qualified = array_keys($qualifierIds);
                $placeholders = implode(',', array_fill(0, count($qualified), '?'));
                $eliminate = $db->prepare(
                    "UPDATE participants SET losses = 1
                     WHERE tournament_id = ? AND id NOT IN ($placeholders)"
                );
                $eliminate->execute(array_merge([$tournamentId], $qualified));
            }

            $completedMatches = $db->prepare(
                "SELECT bracket, player_one_id, player_two_id, winner_id
                 FROM matches
                 WHERE tournament_id = :tournament_id
                   AND round_number <= :round_number
                   AND status = 'completed'
                 ORDER BY round_number, match_number"
            );
            $completedMatches->execute([
                'tournament_id' => $tournamentId,
                'round_number' => $match['round_number'],
            ]);
            $incrementLoss = $db->prepare('UPDATE participants SET losses = losses + 1 WHERE id = :id');

            foreach ($completedMatches->fetchAll() as $completedMatch) {
                $bracket = (string) $completedMatch['bracket'];
                if ($bracket === 'Rundenturnier' || str_starts_with($bracket, 'Gruppe ')) {
                    continue;
                }

                $loserId = (int) $completedMatch['player_one_id'] === (int) $completedMatch['winner_id']
                    ? (int) $completedMatch['player_two_id']
                    : (int) $completedMatch['player_one_id'];
                $incrementLoss->execute(['id' => $loserId]);
            }

            $setInProgress = $db->prepare(
                "UPDATE tournaments SET status = 'in_progress', updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            );
            $setInProgress->execute(['id' => $tournamentId]);
            advanceTournament($db, $tournamentId);
        }

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}

function validateMatchScore(int $scoreOne, int $scoreTwo): void
{
    if ($scoreOne < 0 || $scoreTwo < 0 || $scoreOne === $scoreTwo) {
        throw new DomainException('Bitte gib zwei unterschiedliche, nicht negative Ergebnisse ein.');
    }
}

function groupLabel(int $index): string
{
    $label = '';
    do {
        $label = chr(ord('A') + ($index % 26)) . $label;
        $index = intdiv($index, 26) - 1;
    } while ($index >= 0);

    return $label;
}

function assignDartboard(PDO $db, int $tournamentId, int $matchId, string $boardLabel): void
{
    $boardLabel = trim($boardLabel);

    if (mb_strlen($boardLabel) > 40) {
        throw new DomainException('Die Dartboard-Bezeichnung darf höchstens 40 Zeichen lang sein.');
    }

    $tournament = tournamentById($db, $tournamentId);

    if ($tournament === null || $tournament['status'] !== 'in_progress') {
        throw new DomainException('Dartboards können nur bei laufenden Turnieren zugewiesen werden.');
    }

    if ($boardLabel !== '') {
        $duplicate = $db->prepare(
            "SELECT 1
             FROM matches AS target
             JOIN matches AS other
               ON other.tournament_id = target.tournament_id
              AND other.round_number = target.round_number
              AND other.id <> target.id
             WHERE target.id = :match_id
               AND target.tournament_id = :tournament_id
               AND target.status = 'pending'
               AND other.status = 'pending'
               AND other.board_label = :board_label"
        );
        $duplicate->execute([
            'match_id' => $matchId,
            'tournament_id' => $tournamentId,
            'board_label' => $boardLabel,
        ]);

        if ($duplicate->fetchColumn()) {
            throw new DomainException('Dieses Dartboard ist in der Runde bereits einer anderen offenen Paarung zugewiesen.');
        }
    }

    $statement = $db->prepare(
        "UPDATE matches
         SET board_label = :board_label
         WHERE id = :match_id AND tournament_id = :tournament_id AND status = 'pending'"
    );
    $statement->execute([
        'board_label' => $boardLabel !== '' ? $boardLabel : null,
        'match_id' => $matchId,
        'tournament_id' => $tournamentId,
    ]);

    if ($statement->rowCount() === 0) {
        $check = $db->prepare(
            "SELECT 1 FROM matches
             WHERE id = :match_id AND tournament_id = :tournament_id AND status = 'pending'"
        );
        $check->execute(['match_id' => $matchId, 'tournament_id' => $tournamentId]);

        if (!$check->fetchColumn()) {
            throw new DomainException('Die offene Paarung wurde nicht gefunden.');
        }
    }
}

function createRoundRobinMatches(PDO $db, int $tournamentId, array $groups): void
{
    $schedule = [];

    foreach ($groups as $group) {
        $rotation = array_map('intval', $group['players']);

        if (count($rotation) % 2 !== 0) {
            $rotation[] = 0;
        }

        $roundCount = count($rotation) - 1;

        for ($round = 0; $round < $roundCount; $round++) {
            for ($index = 0; $index < count($rotation) / 2; $index++) {
                $playerOne = $rotation[$index];
                $playerTwo = $rotation[count($rotation) - 1 - $index];

                if ($playerOne !== 0 && $playerTwo !== 0) {
                    $schedule[$round + 1][] = [
                        'bracket' => $group['bracket'],
                        'player_one_id' => $playerOne,
                        'player_two_id' => $playerTwo,
                    ];
                }
            }

            $lastPlayer = array_pop($rotation);
            array_splice($rotation, 1, 0, [$lastPlayer]);
        }
    }

    $insert = $db->prepare(
        "INSERT INTO matches
            (tournament_id, round_number, match_number, bracket, player_one_id, player_two_id, status)
         VALUES
            (:tournament_id, :round_number, :match_number, :bracket, :player_one_id, :player_two_id, 'pending')"
    );

    foreach ($schedule as $roundNumber => $fixtures) {
        foreach ($fixtures as $matchNumber => $fixture) {
            $insert->execute([
                'tournament_id' => $tournamentId,
                'round_number' => $roundNumber,
                'match_number' => $matchNumber + 1,
                'bracket' => $fixture['bracket'],
                'player_one_id' => $fixture['player_one_id'],
                'player_two_id' => $fixture['player_two_id'],
            ]);
        }
    }
}

function standingsForTournament(PDO $db, array $tournament): array
{
    $format = (string) ($tournament['format'] ?? 'knockout');

    if (!in_array($format, ['round_robin', 'groups_ko'], true)) {
        return [];
    }

    $participants = participantsForTournament($db, (int) $tournament['id']);
    $matches = matchesForTournament($db, (int) $tournament['id']);
    $standings = [];

    foreach ($participants as $participant) {
        $groupName = $format === 'round_robin' ? 'Rundenturnier' : null;

        if ($groupName !== null) {
            $standings[$groupName][(int) $participant['id']] = [
                'id' => (int) $participant['id'],
                'name' => (string) $participant['name'],
                'played' => 0,
                'wins' => 0,
                'losses' => 0,
                'score_for' => 0,
                'score_against' => 0,
                'difference' => 0,
            ];
        }
    }

    foreach ($matches as $match) {
        $bracket = (string) $match['bracket'];
        $isGroupMatch = $format === 'round_robin'
            ? $bracket === 'Rundenturnier'
            : str_starts_with($bracket, 'Gruppe ');

        if (!$isGroupMatch) {
            continue;
        }

        $standings[$bracket] ??= [];
        foreach (['player_one', 'player_two'] as $player) {
            $id = (int) $match[$player . '_id'];
            if ($id > 0 && !isset($standings[$bracket][$id])) {
                $standings[$bracket][$id] = [
                    'id' => $id,
                    'name' => (string) $match[$player . '_name'],
                    'played' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'score_for' => 0,
                    'score_against' => 0,
                    'difference' => 0,
                ];
            }
        }

        if ($match['status'] !== 'completed') {
            continue;
        }

        $oneId = (int) $match['player_one_id'];
        $twoId = (int) $match['player_two_id'];
        $oneScore = (int) $match['score_one'];
        $twoScore = (int) $match['score_two'];
        $standings[$bracket][$oneId]['played']++;
        $standings[$bracket][$twoId]['played']++;
        $standings[$bracket][$oneId]['score_for'] += $oneScore;
        $standings[$bracket][$oneId]['score_against'] += $twoScore;
        $standings[$bracket][$twoId]['score_for'] += $twoScore;
        $standings[$bracket][$twoId]['score_against'] += $oneScore;

        if ($oneScore > $twoScore) {
            $standings[$bracket][$oneId]['wins']++;
            $standings[$bracket][$twoId]['losses']++;
        } else {
            $standings[$bracket][$twoId]['wins']++;
            $standings[$bracket][$oneId]['losses']++;
        }
    }

    foreach ($standings as &$groupStandings) {
        foreach ($groupStandings as &$standing) {
            $standing['difference'] = $standing['score_for'] - $standing['score_against'];
        }
        unset($standing);

        uasort($groupStandings, static function (array $left, array $right): int {
            return $right['wins'] <=> $left['wins']
                ?: $right['difference'] <=> $left['difference']
                ?: $right['score_for'] <=> $left['score_for']
                ?: strcasecmp($left['name'], $right['name']);
        });
        $groupStandings = array_values($groupStandings);
    }
    unset($groupStandings);

    return $standings;
}

function advanceTournament(PDO $db, int $tournamentId): void
{
    $tournament = tournamentById($db, $tournamentId);

    if ($tournament === null || $tournament['status'] !== 'in_progress') {
        return;
    }

    $mode = $tournament['mode'];
    $format = (string) ($tournament['format'] ?? 'knockout');

    if ($format === 'round_robin') {
        $pending = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = :id AND status = 'pending'");
        $pending->execute(['id' => $tournamentId]);

        if ((int) $pending->fetchColumn() === 0) {
            $db->prepare("UPDATE tournaments SET status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => $tournamentId]);
        }

        return;
    }

    if ($format === 'groups_ko') {
        $knockout = $db->prepare(
            "SELECT COUNT(*) FROM matches WHERE tournament_id = :id AND bracket = 'K.-o.-Runde'"
        );
        $knockout->execute(['id' => $tournamentId]);

        if ((int) $knockout->fetchColumn() === 0) {
            $pending = $db->prepare(
                "SELECT COUNT(*) FROM matches
                 WHERE tournament_id = :id AND bracket LIKE 'Gruppe %' AND status = 'pending'"
            );
            $pending->execute(['id' => $tournamentId]);

            if ((int) $pending->fetchColumn() > 0) {
                return;
            }

            $groupStandings = standingsForTournament($db, $tournament);
            $qualifiersPerGroup = (int) $tournament['qualifiers_per_group'];
            $groups = [];
            foreach ($groupStandings as $group => $standings) {
                $groups[$group] = array_slice($standings, 0, $qualifiersPerGroup);
            }

            $qualifiedPlayers = [];
            $groupNames = array_keys($groups);
            for ($rank = 0; $rank < intdiv($qualifiersPerGroup, 2); $rank++) {
                $opponentRank = $qualifiersPerGroup - 1 - $rank;
                foreach ($groupNames as $groupIndex => $group) {
                    $opponentGroup = $groupNames[($groupIndex + 1) % count($groupNames)];
                    $qualifiedPlayers[] = (int) $groups[$group][$rank]['id'];
                    $qualifiedPlayers[] = (int) $groups[$opponentGroup][$opponentRank]['id'];
                }
            }

            if ($qualifiersPerGroup % 2 === 1) {
                $middleRank = intdiv($qualifiersPerGroup, 2);
                for ($groupIndex = 0; $groupIndex + 1 < count($groupNames); $groupIndex += 2) {
                    $qualifiedPlayers[] = (int) $groups[$groupNames[$groupIndex]][$middleRank]['id'];
                    $qualifiedPlayers[] = (int) $groups[$groupNames[$groupIndex + 1]][$middleRank]['id'];
                }
                if (count($groupNames) % 2 === 1) {
                    $lastGroup = $groupNames[array_key_last($groupNames)];
                    $qualifiedPlayers[] = (int) $groups[$lastGroup][$middleRank]['id'];
                }
            }

            if (count($qualifiedPlayers) < 2) {
                throw new RuntimeException('Die Gruppenphase hat nicht genügend qualifizierte Spieler für die K.-o.-Runde ergeben.');
            }

            $placeholders = implode(',', array_fill(0, count($qualifiedPlayers), '?'));
            $eliminate = $db->prepare(
                "UPDATE participants SET losses = 1
                 WHERE tournament_id = ? AND id NOT IN ($placeholders)"
            );
            $eliminate->execute(array_merge([$tournamentId], $qualifiedPlayers));

            $statement = $db->prepare('SELECT MAX(round_number) FROM matches WHERE tournament_id = :id');
            $statement->execute(['id' => $tournamentId]);
            $roundNumber = (int) $statement->fetchColumn() + 1;
            createRoundMatches($db, $tournamentId, $roundNumber, [[
                'bracket' => 'K.-o.-Runde',
                'players' => $qualifiedPlayers,
                'preserve_order' => true,
            ]]);

            return;
        }
    }

    $maximumLosses = $mode === 'double_ko' ? 2 : 1;
    $statement = $db->prepare(
        'SELECT id, losses, bye_count
         FROM participants
         WHERE tournament_id = :tournament_id AND losses < :maximum_losses
         ORDER BY id'
    );
    $statement->execute(['tournament_id' => $tournamentId, 'maximum_losses' => $maximumLosses]);
    $active = $statement->fetchAll();

    if (count($active) <= 1) {
        $statement = $db->prepare(
            "UPDATE tournaments SET status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['id' => $tournamentId]);

        return;
    }

    $statement = $db->prepare('SELECT MAX(round_number) FROM matches WHERE tournament_id = :id');
    $statement->execute(['id' => $tournamentId]);
    $roundNumber = (int) $statement->fetchColumn();

    if ($roundNumber > 0) {
        $statement = $db->prepare(
            "SELECT COUNT(*) FROM matches
             WHERE tournament_id = :id AND round_number = :round AND status = 'pending'"
        );
        $statement->execute(['id' => $tournamentId, 'round' => $roundNumber]);

        if ((int) $statement->fetchColumn() > 0) {
            return;
        }
    }

    $roundNumber++;
    $groups = [];

    if ($mode === 'ko') {
        $statement = $db->prepare(
            'SELECT winner_id FROM matches
             WHERE tournament_id = :tournament_id AND round_number = :round_number
             ORDER BY match_number'
        );
        $statement->execute(['tournament_id' => $tournamentId, 'round_number' => $roundNumber - 1]);
        $winners = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $groups[] = [
            'bracket' => 'Sieger-Runde',
            'players' => $winners,
            'preserve_order' => true,
        ];
    } else {
        $statement = $db->prepare(
            'SELECT * FROM matches
             WHERE tournament_id = :tournament_id AND round_number = :round_number
             ORDER BY match_number'
        );
        $statement->execute(['tournament_id' => $tournamentId, 'round_number' => $roundNumber - 1]);
        $previousRound = $statement->fetchAll();
        $lossesById = participantLosses($db, $tournamentId);
        $byLosses = [0 => [], 1 => []];

        foreach ($previousRound as $previousMatch) {
            $winnerId = (int) $previousMatch['winner_id'];
            $winnerLosses = $lossesById[$winnerId];
            $byLosses[$winnerLosses][] = $winnerId;

            if ($previousMatch['status'] !== 'completed') {
                continue;
            }

            $loserId = (int) $previousMatch['player_one_id'] === $winnerId
                ? (int) $previousMatch['player_two_id']
                : (int) $previousMatch['player_one_id'];
            $loserLosses = $lossesById[$loserId];

            if ($loserLosses < 2) {
                $byLosses[$loserLosses][] = $loserId;
            }
        }

        if (count($active) === 2 && count($byLosses[0]) === 1 && count($byLosses[1]) === 1) {
            $groups[] = [
                'bracket' => 'Finale',
                'players' => array_merge($byLosses[0], $byLosses[1]),
                'preserve_order' => true,
            ];
        } elseif (count($active) === 2 && count($byLosses[1]) === 2) {
            $groups[] = [
                'bracket' => 'Finale (Rückspiel)',
                'players' => $byLosses[1],
                'preserve_order' => true,
            ];
        } else {
            if ($byLosses[0] !== []) {
                $groups[] = [
                    'bracket' => 'Sieger-Runde',
                    'players' => $byLosses[0],
                    'preserve_order' => true,
                ];
            }
            if ($byLosses[1] !== []) {
                $groups[] = [
                    'bracket' => 'Verlierer-Runde',
                    'players' => $byLosses[1],
                    'preserve_order' => true,
                ];
            }
        }
    }

    createRoundMatches($db, $tournamentId, $roundNumber, $groups);
}

function createRoundMatches(PDO $db, int $tournamentId, int $roundNumber, array $groups): void
{
    $matchNumber = 1;
    $insertMatch = $db->prepare(
        'INSERT INTO matches
            (tournament_id, round_number, match_number, bracket, player_one_id, player_two_id, winner_id, status)
         VALUES
            (:tournament_id, :round_number, :match_number, :bracket, :player_one_id, :player_two_id, :winner_id, :status)'
    );

    foreach ($groups as $group) {
        $players = array_map('intval', $group['players']);

        if (count($players) > 1 && !($group['preserve_order'] ?? false)) {
            pairPlayers($db, $tournamentId, $players);
        }

        while (count($players) >= 2) {
            $playerOne = array_shift($players);
            $playerTwo = array_shift($players);
            $insertMatch->execute([
                'tournament_id' => $tournamentId,
                'round_number' => $roundNumber,
                'match_number' => $matchNumber++,
                'bracket' => $group['bracket'],
                'player_one_id' => $playerOne,
                'player_two_id' => $playerTwo,
                'winner_id' => null,
                'status' => 'pending',
            ]);
        }

        if ($players !== []) {
            $byePlayer = $players[0];
            $insertMatch->execute([
                'tournament_id' => $tournamentId,
                'round_number' => $roundNumber,
                'match_number' => $matchNumber++,
                'bracket' => $group['bracket'],
                'player_one_id' => $byePlayer,
                'player_two_id' => null,
                'winner_id' => $byePlayer,
                'status' => 'bye',
            ]);

            $statement = $db->prepare('UPDATE participants SET bye_count = bye_count + 1 WHERE id = :id');
            $statement->execute(['id' => $byePlayer]);
        }
    }
}

function pairPlayers(PDO $db, int $tournamentId, array &$players): void
{
    shuffle($players);
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM matches
         WHERE tournament_id = :tournament_id
           AND ((player_one_id = :player_one AND player_two_id = :player_two)
             OR (player_one_id = :player_two_reverse AND player_two_id = :player_one_reverse))'
    );

    $remaining = $players;
    $paired = [];

    while (count($remaining) > 1) {
        $playerOne = array_shift($remaining);
        $bestIndex = 0;
        $fewestMeetings = PHP_INT_MAX;

        foreach ($remaining as $index => $candidate) {
            $statement->execute([
                'tournament_id' => $tournamentId,
                'player_one' => $playerOne,
                'player_two' => $candidate,
                'player_one_reverse' => $playerOne,
                'player_two_reverse' => $candidate,
            ]);
            $meetings = (int) $statement->fetchColumn();

            if ($meetings < $fewestMeetings) {
                $fewestMeetings = $meetings;
                $bestIndex = $index;
            }
        }

        $opponent = $remaining[$bestIndex];
        array_splice($remaining, $bestIndex, 1);
        $paired[] = $playerOne;
        $paired[] = $opponent;
    }

    if ($remaining !== []) {
        $paired[] = $remaining[0];
    }

    $players = $paired;
}

function participantsForTournament(PDO $db, int $tournamentId): array
{
    $statement = $db->prepare('SELECT * FROM participants WHERE tournament_id = :id ORDER BY name, id');
    $statement->execute(['id' => $tournamentId]);

    return $statement->fetchAll();
}

function matchesForTournament(PDO $db, int $tournamentId): array
{
    $statement = $db->prepare(
        'SELECT matches.*,
                one.name AS player_one_name,
                two.name AS player_two_name,
                winner.name AS winner_name
         FROM matches
         LEFT JOIN participants AS one ON one.id = matches.player_one_id
         LEFT JOIN participants AS two ON two.id = matches.player_two_id
         LEFT JOIN participants AS winner ON winner.id = matches.winner_id
         WHERE matches.tournament_id = :id
         ORDER BY round_number, match_number'
    );
    $statement->execute(['id' => $tournamentId]);

    return $statement->fetchAll();
}

function knockoutStageLabel(int $competitorCount, array $tournament, string $bracket): ?string
{
    if (str_starts_with($bracket, 'Finale')) {
        return $bracket === 'Finale (Rückspiel)' ? 'Finale · Rückspiel' : 'Finale';
    }

    if (!in_array($bracket, ['Sieger-Runde', 'Verlierer-Runde', 'K.-o.-Runde'], true)) {
        return null;
    }

    $stage = match ($competitorCount) {
        8 => 'Viertelfinale',
        4 => 'Halbfinale',
        2 => ($tournament['mode'] ?? null) === 'double_ko'
            ? 'Finale der ' . ($bracket === 'Verlierer-Runde' ? 'Verlierer-Runde' : 'Sieger-Runde')
            : 'Finale',
        default => null,
    };

    if ($stage !== null && ($tournament['mode'] ?? null) === 'double_ko' && $competitorCount > 2) {
        $stage .= ' · ' . $bracket;
    }

    return $stage;
}

function matchDisplayLabels(array $matches, ?array $tournament): array
{
    if ($tournament === null) {
        return [];
    }

    $groups = [];
    foreach ($matches as $match) {
        $key = $match['round_number'] . '|' . $match['bracket'];
        $groups[$key]['bracket'] = (string) $match['bracket'];
        $groups[$key]['matches'][] = $match;
        foreach (['player_one_id', 'player_two_id'] as $playerColumn) {
            if ($match[$playerColumn] !== null) {
                $groups[$key]['players'][(int) $match[$playerColumn]] = true;
            }
        }
    }

    $labels = [];
    foreach ($groups as $group) {
        $bracket = $group['bracket'];
        $stage = knockoutStageLabel(count($group['players'] ?? []), $tournament, $bracket);
        $payoutLabel = null;

        if (preg_match('/\bplatz\s*(\d+)\b/i', $bracket, $placementMatch)) {
            $place = (int) $placementMatch[1];
            $stage = 'Spiel um Platz ' . $place;
            if ($place <= (int) $tournament['payout_places']
                && (int) $tournament['payout_percent_basis_points'] > 0) {
                $payoutLabel = 'Ausschüttungsplatz';
            }
        } elseif (str_starts_with((string) $stage, 'Finale')
            && (int) $tournament['payout_percent_basis_points'] > 0) {
            $payoutPlaces = min(2, (int) $tournament['payout_places']);
            $payoutLabel = $payoutPlaces === 1
                ? 'Ausschüttung · Platz 1'
                : 'Ausschüttung · Plätze 1–2';
        }

        foreach ($group['matches'] as $match) {
            $labels[(int) $match['id']] = [
                'stage' => $stage,
                'payout' => $payoutLabel,
            ];
        }
    }

    return $labels;
}

function participantLosses(PDO $db, int $tournamentId): array
{
    $statement = $db->prepare('SELECT id, losses FROM participants WHERE tournament_id = :id');
    $statement->execute(['id' => $tournamentId]);
    $losses = [];

    foreach ($statement->fetchAll() as $participant) {
        $losses[(int) $participant['id']] = (int) $participant['losses'];
    }

    return $losses;
}

function upcomingMatchPreviews(PDO $db, ?array $tournament, array $matches): array
{
    if ($tournament === null
        || $tournament['status'] !== 'in_progress'
        || $matches === []
        || ($tournament['format'] ?? 'knockout') === 'round_robin') {
        return [];
    }

    if (($tournament['format'] ?? 'knockout') === 'groups_ko') {
        $hasKnockoutMatches = array_filter(
            $matches,
            static fn (array $match): bool => $match['bracket'] === 'K.-o.-Runde'
        );
        if ($hasKnockoutMatches === []) {
            return [];
        }
    }

    $roundNumber = (int) max(array_column($matches, 'round_number'));
    $currentRound = array_values(array_filter(
        $matches,
        static fn (array $match): bool => (int) $match['round_number'] === $roundNumber
    ));

    if (!array_filter($currentRound, static fn (array $match): bool => $match['status'] === 'pending')) {
        return [];
    }

    $lossesById = participantLosses($db, (int) $tournament['id']);
    $maximumLosses = $tournament['mode'] === 'double_ko' ? 2 : 1;
    $activeCount = count(array_filter(
        $lossesById,
        static fn (int $losses): bool => $losses < $maximumLosses
    ));

    if ($activeCount <= 2) {
        return [];
    }

    $groups = $tournament['mode'] === 'double_ko' ? [0 => [], 1 => []] : ['ko' => []];

    foreach ($currentRound as $match) {
        $matchNumber = (int) $match['match_number'];

        if ($match['status'] === 'completed' || $match['status'] === 'bye') {
            $winnerId = (int) $match['winner_id'];
            $winnerLosses = $lossesById[$winnerId];
            $groupKey = $tournament['mode'] === 'double_ko' ? $winnerLosses : 'ko';
            $groups[$groupKey][] = [
                'id' => $winnerId,
                'name' => (string) $match['winner_name'],
                'known' => true,
            ];

            if ($match['status'] === 'completed' && $tournament['mode'] === 'double_ko') {
                $loserId = (int) $match['player_one_id'] === $winnerId
                    ? (int) $match['player_two_id']
                    : (int) $match['player_one_id'];
                $loserLosses = $lossesById[$loserId];

                if ($loserLosses < $maximumLosses) {
                    $groups[$loserLosses][] = [
                        'id' => $loserId,
                        'name' => $loserId === (int) $match['player_one_id']
                            ? (string) $match['player_one_name']
                            : (string) $match['player_two_name'],
                        'known' => true,
                    ];
                }
            }

            continue;
        }

        if ($tournament['mode'] === 'double_ko') {
            $oneLosses = $lossesById[(int) $match['player_one_id']];
            $twoLosses = $lossesById[(int) $match['player_two_id']];

            if ($oneLosses !== $twoLosses) {
                continue;
            }

            $groups[$oneLosses][] = [
                'id' => null,
                'name' => 'Sieger aus Spiel ' . $matchNumber,
                'known' => false,
            ];

            if ($oneLosses + 1 < $maximumLosses) {
                $groups[$oneLosses + 1][] = [
                    'id' => null,
                    'name' => 'Verlierer aus Spiel ' . $matchNumber,
                    'known' => false,
                ];
            }
        } else {
            $groups['ko'][] = [
                'id' => null,
                'name' => 'Sieger aus Spiel ' . $matchNumber,
                'known' => false,
            ];
        }
    }

    if ($tournament['mode'] === 'double_ko' && count($groups[0]) === 1 && count($groups[1]) === 1) {
        $groups = ['Finale' => array_merge($groups[0], $groups[1])];
    } elseif (
        $tournament['mode'] === 'double_ko'
        && $activeCount === 2
        && count($groups[1]) === 2
    ) {
        $groups = ['Finale (Rückspiel)' => $groups[1]];
    } elseif ($tournament['mode'] === 'double_ko') {
        $groups = array_filter([
            'Sieger-Runde' => $groups[0],
            'Verlierer-Runde' => $groups[1],
        ], static fn (array $group): bool => $group !== []);
    } else {
        $groups = ['Sieger-Runde' => $groups['ko']];
    }

    $previews = [];

    foreach ($groups as $bracket => $players) {
        $stageLabel = knockoutStageLabel(count($players), $tournament, $bracket);
        $payoutLabel = str_starts_with((string) $stageLabel, 'Finale')
            && (int) $tournament['payout_percent_basis_points'] > 0
            ? ((int) $tournament['payout_places'] === 1
                ? 'Ausschüttung · Platz 1'
                : 'Ausschüttung · Plätze 1–2')
            : null;

        for ($index = 0; $index < count($players); $index += 2) {
            $one = $players[$index];
            $two = $players[$index + 1] ?? null;

            if ($two === null || (!$one['known'] && !$two['known'])) {
                continue;
            }

            $previews[] = [
                'bracket' => $bracket,
                'stage_label' => $stageLabel,
                'payout_label' => $payoutLabel,
                'player_one_name' => $one['name'],
                'player_two_name' => $two['name'] ?? 'Freilos',
            ];
        }
    }

    return $previews === [] ? [] : [
        'round_number' => $roundNumber + 1,
        'matches' => $previews,
    ];
}
