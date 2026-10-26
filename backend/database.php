<?php
declare(strict_types=1);

function database(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DART_DB_HOST');
    $databaseName = getenv('DART_DB_NAME');
    $username = getenv('DART_DB_USER');
    $password = getenv('DART_DB_PASSWORD');
    $port = getenv('DART_DB_PORT') ?: '3306';

    if ($host === false || $host === ''
        || $databaseName === false || $databaseName === ''
        || $username === false || $username === ''
        || $password === false || $password === '') {
        throw new RuntimeException(
            'Die Datenbankverbindung ist unvollständig: DART_DB_HOST, DART_DB_NAME, '
            . 'DART_DB_USER und DART_DB_PASSWORD müssen gesetzt sein.'
        );
    }

    if (!preg_match('/^\d+$/D', $port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('DART_DB_PORT muss eine gültige TCP-Portnummer enthalten.');
    }

    $newConnection = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            (int) $port,
            $databaseName
        ),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    initializeDatabase($newConnection);
    $connection = $newConnection;

    return $connection;
}

function initializeDatabase(PDO $connection): void
{
    $connection->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(32) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY users_username_unique (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);

    $connection->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tournaments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            event_date DATE NULL,
            entry_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
            payout_percent_basis_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            payout_places TINYINT UNSIGNED NOT NULL DEFAULT 3,
            payout_distribution VARCHAR(16) NOT NULL DEFAULT 'flat',
            mode VARCHAR(16) NULL,
            format VARCHAR(16) NOT NULL DEFAULT 'knockout',
            qualifiers_per_group TINYINT UNSIGNED NOT NULL DEFAULT 2,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            archived TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY tournaments_by_event_date (event_date DESC, id DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);

    $connection->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS participants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tournament_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(80) NOT NULL,
            losses INT UNSIGNED NOT NULL DEFAULT 0,
            bye_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY participants_tournament_name_unique (tournament_id, name),
            KEY participants_by_tournament (tournament_id, id),
            CONSTRAINT participants_tournament_fk
                FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);

    $connection->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS matches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tournament_id BIGINT UNSIGNED NOT NULL,
            round_number INT UNSIGNED NOT NULL,
            match_number INT UNSIGNED NOT NULL,
            bracket VARCHAR(64) NOT NULL,
            player_one_id BIGINT UNSIGNED NULL,
            player_two_id BIGINT UNSIGNED NULL,
            winner_id BIGINT UNSIGNED NULL,
            score_one INT UNSIGNED NULL,
            score_two INT UNSIGNED NULL,
            board_label VARCHAR(40) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY matches_tournament_round_number_unique (tournament_id, round_number, match_number),
            KEY matches_by_tournament_round (tournament_id, round_number, match_number),
            CONSTRAINT matches_tournament_fk
                FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
            CONSTRAINT matches_player_one_fk
                FOREIGN KEY (player_one_id) REFERENCES participants(id) ON DELETE SET NULL,
            CONSTRAINT matches_player_two_fk
                FOREIGN KEY (player_two_id) REFERENCES participants(id) ON DELETE SET NULL,
            CONSTRAINT matches_winner_fk
                FOREIGN KEY (winner_id) REFERENCES participants(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);

    ensureDatabaseColumn($connection, 'tournaments', 'payout_percent_basis_points',
        'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
    ensureDatabaseColumn($connection, 'tournaments', 'payout_places',
        'TINYINT UNSIGNED NOT NULL DEFAULT 3');
    ensureDatabaseColumn($connection, 'tournaments', 'payout_distribution',
        "VARCHAR(16) NOT NULL DEFAULT 'flat'");
    ensureDatabaseColumn($connection, 'tournaments', 'archived',
        'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    ensureDatabaseColumn($connection, 'tournaments', 'format',
        "VARCHAR(16) NOT NULL DEFAULT 'knockout'");
    ensureDatabaseColumn($connection, 'tournaments', 'qualifiers_per_group',
        'TINYINT UNSIGNED NOT NULL DEFAULT 2');
    ensureDatabaseColumn($connection, 'matches', 'board_label', 'VARCHAR(40) NULL');

    ensureDatabaseIndex($connection, 'tournaments', 'tournaments_by_event_date',
        'event_date, id');
    ensureDatabaseIndex($connection, 'participants', 'participants_by_tournament',
        'tournament_id, id');
    ensureDatabaseIndex($connection, 'matches', 'matches_by_tournament_round',
        'tournament_id, round_number, match_number');
}

function ensureDatabaseColumn(PDO $connection, string $table, string $column, string $definition): void
{
    $statement = $connection->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $statement->execute(['table_name' => $table, 'column_name' => $column]);

    if ((int) $statement->fetchColumn() === 0) {
        $connection->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s',
            $table,
            $column,
            $definition
        ));
    }
}

function ensureDatabaseIndex(PDO $connection, string $table, string $index, string $columns): void
{
    $statement = $connection->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $statement->execute(['table_name' => $table, 'index_name' => $index]);

    if ((int) $statement->fetchColumn() === 0) {
        $connection->exec(sprintf(
            'CREATE INDEX `%s` ON `%s` (%s)',
            $index,
            $table,
            $columns
        ));
    }
}
