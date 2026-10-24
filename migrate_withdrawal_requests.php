<?php
/**
 * EcoCycle — additive simulated withdrawal checkout migration.
 *
 * Run from the project directory: php migrate_withdrawal_requests.php
 * Safe to rerun; existing wallet and transaction data are preserved.
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
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS withdrawal_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            wallet_id INT NOT NULL,
            reference_id VARCHAR(50) NOT NULL,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            idempotency_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            authorization_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            authorization_token_used_at DATETIME NULL,
            pin_verified_at DATETIME NULL,
            status ENUM('pending','authorized','completed','expired','cancelled') NOT NULL DEFAULT 'pending',
            points_amount INT NOT NULL,
            points_reserved INT NOT NULL DEFAULT 0,
            gross_amount DECIMAL(10,2) NOT NULL,
            fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            net_amount DECIMAL(10,2) NOT NULL,
            payout_method ENUM('voucher','bank_transfer','ewallet') NOT NULL,
            provider VARCHAR(40) NOT NULL,
            payout_label VARCHAR(100) NULL,
            account_holder VARCHAR(100) NULL,
            account_last4 VARCHAR(4) NULL,
            voucher_code VARCHAR(40) NULL,
            transaction_id INT NULL,
            expires_at DATETIME NOT NULL,
            authorized_at DATETIME NULL,
            completed_at DATETIME NULL,
            expired_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_withdrawal_request_reference (reference_id),
            UNIQUE KEY uq_withdrawal_request_token (token_hash),
            UNIQUE KEY uq_withdrawal_request_idempotency (idempotency_key_hash),
            INDEX idx_withdrawal_request_user_created (user_id, created_at),
            INDEX idx_withdrawal_request_status_expiry (status, expires_at),
            INDEX idx_withdrawal_request_transaction (transaction_id),
            CONSTRAINT fk_withdrawal_request_user
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_withdrawal_request_wallet
                FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
            CONSTRAINT fk_withdrawal_request_transaction
                FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $hasColumn = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $additions = [
        'idempotency_key_hash' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
        'authorization_token_hash' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
        'authorization_token_used_at' => 'DATETIME NULL',
        'pin_verified_at' => 'DATETIME NULL',
    ];
    foreach ($additions as $column => $definition) {
        $hasColumn->execute(['withdrawal_requests', $column]);
        if ((int) $hasColumn->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE withdrawal_requests ADD COLUMN ' . $column . ' ' . $definition);
            echo "[OK] Added withdrawal_requests.{$column}.\n";
        } else {
            echo "[--] withdrawal_requests.{$column} already exists.\n";
        }
    }

    $hasIndex = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $hasIndex->execute(['withdrawal_requests', 'uq_withdrawal_request_idempotency']);
    if ((int) $hasIndex->fetchColumn() === 0) {
        $pdo->exec(
            'CREATE UNIQUE INDEX uq_withdrawal_request_idempotency
               ON withdrawal_requests (idempotency_key_hash)'
        );
        echo "[OK] Added withdrawal request idempotency index.\n";
    } else {
        echo "[--] Withdrawal request idempotency index already exists.\n";
    }

    echo "[OK] Ensured withdrawal_requests table.\n";
    echo "Migration complete.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "[ERROR] Migration failed: " . $ex->getMessage() . "\n");
    exit(1);
}
