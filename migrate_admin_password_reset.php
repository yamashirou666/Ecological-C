<?php
/**
 * EcoCycle — admin password reset migration.
 *
 * Adds the forced-password-change and session-version fields and records
 * administrative password resets without storing temporary passwords.
 *
 * Run from the project directory: php migrate_admin_password_reset.php
 * Safe to rerun; existing account data and password hashes are preserved.
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
    $hasColumn = static function (string $column) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['users', $column]);
        return (int) $stmt->fetchColumn() > 0;
    };

    echo "EcoCycle Admin Password Reset Migration\n";
    echo "========================================\n\n";

    if (!$hasColumn('must_change_password')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
        echo "[OK] Added users.must_change_password (existing users remain unchanged).\n";
    } else {
        echo "[--] users.must_change_password already exists.\n";
    }

    if (!$hasColumn('auth_session_version')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN auth_session_version BIGINT UNSIGNED NOT NULL DEFAULT 0');
        echo "[OK] Added users.auth_session_version.\n";
    } else {
        echo "[--] users.auth_session_version already exists.\n";
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_password_reset_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id INT NOT NULL,
            target_user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_password_reset_admin (admin_user_id, created_at),
            INDEX idx_admin_password_reset_target (target_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    echo "[OK] Ensured admin_password_reset_logs audit table.\n";
    echo "\nMigration complete.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "[ERROR] Migration failed: " . $ex->getMessage() . "\n");
    exit(1);
}
