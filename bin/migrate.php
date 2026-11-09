<?php

declare(strict_types=1);

// Applies pending SQL files from /migrations in name order.
// Usage: php bin/migrate.php

require __DIR__ . '/../vendor/autoload.php';

use App\Core\App;

App::boot(dirname(__DIR__));
$db = App::db();

$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(190) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$done = array_flip($db->column('SELECT name FROM schema_migrations'));
$files = glob(App::$root . '/migrations/*.sql') ?: [];
sort($files);
$applied = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (isset($done[$name])) {
        continue;
    }
    echo "Applying $name ... ";
    $sql = (string) file_get_contents($file);
    // Strip comment lines, then split on semicolons at end of line.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
    $statements = array_filter(array_map('trim', preg_split('/;\s*$/m', $sql) ?: []));
    foreach ($statements as $stmt) {
        $db->pdo->exec($stmt);
    }
    $db->insert('schema_migrations', ['name' => $name]);
    echo "ok\n";
    $applied++;
}

echo $applied ? "Done ($applied applied).\n" : "Nothing to migrate.\n";
