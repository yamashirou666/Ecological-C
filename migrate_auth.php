<?php
/**
 * EcoCycle — additive authentication schema migration.
 *
 * Run once from the project directory: php migrate_auth.php
 * Safe to rerun; existing user data and password hashes are preserved.
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
    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $hasSingleColumnUniqueIndex = static function (string $column) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT INDEX_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND NON_UNIQUE = 0
              GROUP BY INDEX_NAME
             HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = ?
              LIMIT 1'
        );
        $stmt->execute(['users', $column]);
        return $stmt->fetchColumn() !== false;
    };

    echo "EcoCycle Authentication Migration\n";
    echo "=================================\n\n";

    if (!$hasColumn('users', 'username')) {
        $pdo->exec(
            'ALTER TABLE users ADD COLUMN username VARCHAR(12)
             CHARACTER SET ascii COLLATE ascii_general_ci NULL'
        );
        echo "[OK] Added users.username.\n";
    } else {
        echo "[--] users.username already exists.\n";
    }

    if (!$hasColumn('users', 'birthdate')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN birthdate DATE NULL');
        echo "[OK] Added users.birthdate.\n";
    } else {
        echo "[--] users.birthdate already exists.\n";
    }

    if (!$hasColumn('users', 'email_verified_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL');
        echo "[OK] Added users.email_verified_at.\n";
    } else {
        echo "[--] users.email_verified_at already exists.\n";
    }

    $missingUsernames = $pdo->query(
        "SELECT id FROM users WHERE username IS NULL OR username = '' ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
    $update = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?');
    foreach ($missingUsernames as $id) {
        $idText = strtolower(base_convert((string) $id, 10, 36));
        $candidate = 'ec' . $idText;
        $suffix = 0;
        do {
            $exists->execute([$candidate]);
            if (!$exists->fetchColumn()) {
                break;
            }
            $suffix++;
            $candidate = 'ec' . $idText . strtolower(base_convert((string) $suffix, 10, 36));
            if (strlen($candidate) > 12) {
                throw new RuntimeException('Unable to generate a unique username for an existing user.');
            }
        } while (true);
        $update->execute([$candidate, $id]);
    }
    if ($missingUsernames) {
        echo '[OK] Assigned usernames to ' . count($missingUsernames) . " existing user(s).\n";
    }

    $invalidUsername = $pdo->query(
        "SELECT id FROM users
         WHERE username IS NULL OR username = '' OR username NOT REGEXP '^[A-Za-z0-9]{3,12}$'
         LIMIT 1"
    )->fetchColumn();
    if ($invalidUsername !== false) {
        throw new RuntimeException('An existing username is invalid; fix it before rerunning this migration.');
    }

    $duplicateUsername = $pdo->query(
        'SELECT username FROM users GROUP BY username HAVING COUNT(*) > 1 LIMIT 1'
    )->fetchColumn();
    if ($duplicateUsername !== false) {
        throw new RuntimeException('Duplicate usernames exist; resolve them before adding the unique index.');
    }

    $pdo->exec(
        'ALTER TABLE users MODIFY COLUMN username VARCHAR(12)
         CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL'
    );
    if (!$hasSingleColumnUniqueIndex('username')) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_username (username)');
        echo "[OK] Added unique username index.\n";
    } else {
        echo "[--] A unique username index already exists.\n";
    }

    if (!$hasSingleColumnUniqueIndex('email')) {
        $duplicateEmail = $pdo->query(
            'SELECT email FROM users GROUP BY email HAVING COUNT(*) > 1 LIMIT 1'
        )->fetchColumn();
        if ($duplicateEmail !== false) {
            throw new RuntimeException('Duplicate email addresses exist; resolve them before adding the unique index.');
        }
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
        echo "[OK] Added unique email index.\n";
    } else {
        echo "[--] A unique email index already exists.\n";
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            identifier_hash CHAR(64) NOT NULL,
            ip_address VARCHAR(45) NULL,
            was_successful TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempt_identifier_time (identifier_hash, created_at),
            INDEX idx_login_attempt_ip_time (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    echo "[OK] Ensured login_attempts table.\n";

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS email_verifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_email_verification_token (token_hash),
            INDEX idx_email_verification_user (user_id, created_at),
            CONSTRAINT fk_email_verification_user
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    echo "[OK] Ensured email_verifications table.\n";

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_password_reset_token (token_hash),
            INDEX idx_password_reset_user (user_id, created_at),
            CONSTRAINT fk_password_reset_user
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    echo "[OK] Ensured password_resets table.\n";

    echo "\nMigration complete.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "[ERROR] Migration failed: " . $ex->getMessage() . "\n");
    exit(1);
}
