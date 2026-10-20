<?php
/**
 * EcoCycle — one-time database setup.
 *
 * Visit http://localhost/Project%20EchoCycle/setup.php in your browser
 * (or run `php setup.php` from the CLI) to create the `ecocycle` database,
 * build all tables, and load the seed data.
 *
 * Safe to re-run: it recreates tables from scratch (existing data is dropped).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    // 1. Create the database if it does not exist yet.
    $server = db(false);
    $server->exec(
        'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '`
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    echo "[OK] Database `" . DB_NAME . "` is ready.\n";

    // 2. Run the schema + seed script.
    $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Could not read sql/schema.sql');
    }

    $pdo = db(); // now connect *with* the database selected
    $pdo->exec($sql);
    echo "[OK] Tables created and seed data loaded.\n";

    // 3. Quick sanity summary.
    $counts = [
        'partners' => (int) $pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn(),
        'rewards'  => (int) $pdo->query('SELECT COUNT(*) FROM rewards')->fetchColumn(),
        'badges'   => (int) $pdo->query('SELECT COUNT(*) FROM badges')->fetchColumn(),
    ];
    echo sprintf(
        "[OK] Seeded %d partners, %d rewards, %d badges.\n",
        $counts['partners'],
        $counts['rewards'],
        $counts['badges']
    );

    echo "\nSetup complete! You can now open index.php and create an account.\n";
} catch (Throwable $ex) {
    http_response_code(500);
    echo "[ERROR] Setup failed: " . $ex->getMessage() . "\n";
    echo "\nCheck that MySQL/MariaDB is running in XAMPP and that the\n";
    echo "credentials in config/config.php are correct.\n";
}
