<?php

declare(strict_types=1);

// Applies database/migrations/*.sql in filename order, once each.
// Usage: php bin/migrate.php

use App\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

$dir = getenv('MIGRATIONS_DIR') ?: dirname(__DIR__, 2) . '/database/migrations';
$pdo = Database::pdo();

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version     VARCHAR(190) PRIMARY KEY,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$applied = array_flip(array_column(Database::all('SELECT version FROM schema_migrations'), 'version'));
$files = glob($dir . '/*.sql');
sort($files);

$count = 0;
foreach ($files as $file) {
    $version = basename($file);
    if (isset($applied[$version])) {
        continue;
    }

    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    $statements = array_filter(array_map('trim', preg_split('/;\s*(\R|$)/', $sql)));

    echo "Applying {$version} ... ";
    // MySQL DDL auto-commits, so a failed migration must be fixed and re-run by hand.
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    Database::query('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
    echo "done\n";
    $count++;
}

echo $count === 0 ? "Nothing to migrate.\n" : "Applied {$count} migration(s).\n";
