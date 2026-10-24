<?php
/**
 * Add wallet PIN reset timestamps for reset cooldown enforcement.
 *
 * Run from the project directory: php migrate_wallet_pin_reset.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This migration must be run from the command line.\n";
    exit(1);
}

try {
    $pdo = db();
    $column = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $column->execute(['wallets', 'pin_reset_at']);
    if ((int) $column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE wallets ADD COLUMN pin_reset_at DATETIME NULL AFTER pin_created_at');
        echo "[OK] Added wallets.pin_reset_at.\n";
    } else {
        echo "[--] wallets.pin_reset_at already exists.\n";
    }
    echo "Migration complete.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "[ERROR] Migration failed: " . $ex->getMessage() . "\n");
    exit(1);
}
