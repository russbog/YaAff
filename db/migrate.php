<?php

/**
 * CLI migration runner.
 *
 *   php db/migrate.php            apply all pending migrations
 *   php db/migrate.php status     show applied / pending versions
 *   php db/migrate.php rollback   revert the most recent migration
 *
 * Uses the same database driver as the application (SQLite by default).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Migrator.php';

$db = $db ?? new Db();
$migrator = new Migrator($db->driver());

$command = $argv[1] ?? 'migrate';

switch ($command) {
    case 'status':
        $applied = $migrator->appliedVersions();
        $pending = array_keys($migrator->pending());
        echo 'Applied: ' . ($applied ? implode(', ', $applied) : '(none)') . PHP_EOL;
        echo 'Pending: ' . ($pending ? implode(', ', $pending) : '(none)') . PHP_EOL;
        break;

    case 'rollback':
        $steps = (int)($argv[2] ?? 1);
        $reverted = $migrator->rollback($steps);
        echo $reverted ? 'Rolled back: ' . implode(', ', $reverted) . PHP_EOL : 'Nothing to roll back.' . PHP_EOL;
        break;

    case 'migrate':
    default:
        $applied = $migrator->migrate();
        echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : 'Already up to date.' . PHP_EOL;
        break;
}
