<?php
/**
 * EcoCycle — admin migration.
 *
 * Safely upgrades an EXISTING database (without dropping data):
 *   1. Adds the `is_admin` column to `users` if it is missing.
 *   2. Creates or promotes the owner/administrator account "Zyk Granada".
 *
 * Run once from the CLI: php migrate_admin.php
 * Idempotent — safe to run multiple times.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This migration must be run from the command line.\n";
    exit(1);
}

// ---- Owner details ----
const ADMIN_NAME  = 'Zyk Granada';
const ADMIN_EMAIL = 'zyk.granada@ecocycle.local';
const ADMIN_HOOD  = 'Greendale';

try {
    $pdo = db();

    // 1. Add is_admin column if it does not exist.
    $hasColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'is_admin'"
    )->fetchColumn();

    if ((int) $hasColumn === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER last_log_date');
        echo "[OK] Added `is_admin` column to users.\n";
    } else {
        echo "[--] `is_admin` column already present.\n";
    }

    // 2. Create or promote the owner account.
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([ADMIN_EMAIL]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $pdo->prepare('UPDATE users SET name = ?, is_admin = 1 WHERE id = ?')
            ->execute([ADMIN_NAME, $existingId]);
        echo "[OK] Promoted existing account (" . ADMIN_EMAIL . ") to administrator.\n";
    } else {
        $adminPassword = getenv('ECOCYCLE_ADMIN_PASSWORD');
        if ($adminPassword === false || $adminPassword === '') {
            throw new RuntimeException('Set ECOCYCLE_ADMIN_PASSWORD in the environment before creating the administrator.');
        }
        if ($passwordError = passwordPolicyError($adminPassword)) {
            throw new RuntimeException('Administrator password rejected: ' . $passwordError);
        }

        $hasUsername = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'username'"
        )->fetchColumn() > 0;

        if ($hasUsername) {
            $adminUsernameBase = 'zykgranada';
            $adminUsername = $adminUsernameBase;
            $usernameCheck = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
            $suffix = 1;
            while (true) {
                $usernameCheck->execute([$adminUsername]);
                if (!$usernameCheck->fetchColumn()) {
                    break;
                }
                $suffixText = (string) $suffix;
                $adminUsername = substr($adminUsernameBase, 0, 12 - strlen($suffixText)) . $suffixText;
                $suffix++;
            }
            $pdo->prepare(
                'INSERT INTO users (name, username, email, password_hash, neighborhood, is_admin)
                 VALUES (?, ?, ?, ?, ?, 1)'
            )->execute([ADMIN_NAME, $adminUsername, ADMIN_EMAIL, hashPassword($adminPassword), ADMIN_HOOD]);
        } else {
            $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, neighborhood, is_admin) VALUES (?, ?, ?, ?, 1)'
            )->execute([ADMIN_NAME, ADMIN_EMAIL, hashPassword($adminPassword), ADMIN_HOOD]);
        }
        echo "[OK] Created administrator account for " . ADMIN_NAME . ".\n";
    }

    echo "\nDone! The administrator account is ready.\n";
    echo "   Email: " . ADMIN_EMAIL . "\n";
    echo "   Configure ECOCYCLE_ADMIN_PASSWORD before running this migration to create a new administrator.\n";
    echo "\nThe Admin dashboard link will appear in the top navigation once you log in.\n";
} catch (Throwable $ex) {
    http_response_code(500);
    echo "[ERROR] Migration failed: " . $ex->getMessage() . "\n";
}
