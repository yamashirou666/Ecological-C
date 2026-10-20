<?php
/**
 * EcoCycle — Setup Script: Create EcoWallet Tables
 * Run this once to initialize the wallet database schema.
 */

require_once __DIR__ . '/config/config.php';

echo "═══════════════════════════════════════════\n";
echo "   EcoCycle Wallet — Database Setup\n";
echo "═══════════════════════════════════════════\n\n";

try {
    $pdo = db();
    
    // SQL for wallet tables
    $sql = [
        // Wallets table
        "CREATE TABLE IF NOT EXISTS wallets (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            user_id             INT           NOT NULL UNIQUE,
            balance             INT           NOT NULL DEFAULT 0,
            lifetime_earned     INT           NOT NULL DEFAULT 0,
            lifetime_redeemed   INT           NOT NULL DEFAULT 0,
            pin_hash            VARCHAR(255)  NULL,
            pin_created_at      DATETIME      NULL,
            failed_pin_attempts INT           NOT NULL DEFAULT 0,
            locked_until        DATETIME      NULL,
            created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // Transactions table
        "CREATE TABLE IF NOT EXISTS transactions (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            wallet_id          INT          NOT NULL,
            type               ENUM('deposit','withdrawal','redemption') NOT NULL,
            amount             INT          NOT NULL,
            source_description VARCHAR(255) NOT NULL,
            status             ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
            reference_id       VARCHAR(50)  NOT NULL UNIQUE,
            balance_after      INT          NOT NULL,
            receipt_data       JSON         NULL,
            created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_txn_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
            INDEX idx_wallet_created (wallet_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // Payout methods table
        "CREATE TABLE IF NOT EXISTS payout_methods (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            user_id         INT          NOT NULL,
            type            ENUM('voucher','bank_transfer','ewallet') NOT NULL,
            label           VARCHAR(100) NOT NULL,
            account_details VARCHAR(500) NULL,
            is_default      TINYINT(1)   NOT NULL DEFAULT 0,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // PIN reset requests table
        "CREATE TABLE IF NOT EXISTS pin_reset_requests (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            user_id        INT          NOT NULL,
            otp_code_hash  VARCHAR(255) NOT NULL,
            expires_at     DATETIME     NOT NULL,
            verified       TINYINT(1)   NOT NULL DEFAULT 0,
            created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_prr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    
    $created = 0;
    $skipped = 0;
    
    foreach ($sql as $index => $query) {
        try {
            $pdo->exec($query);
            $created++;
            echo "✓ Table created successfully\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $skipped++;
                echo "⊘ Table already exists (skipped)\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "\n═══════════════════════════════════════════\n";
    echo "✓ Setup Complete!\n";
    echo "─────────────────────────────────────────\n";
    echo "Tables Created:  $created\n";
    echo "Tables Skipped:  $skipped\n";
    echo "═══════════════════════════════════════════\n\n";
    
    // Now backfill wallets for existing users
    echo "Backfilling wallets for existing users...\n";
    $users = $pdo->query("SELECT id FROM users WHERE id NOT IN (SELECT DISTINCT user_id FROM wallets)")->fetchAll();
    
    if (!$users) {
        echo "✓ All users already have wallets.\n\n";
    } else {
        $inserted = 0;
        foreach ($users as $user) {
            $stmt = $pdo->prepare("INSERT INTO wallets (user_id) VALUES (?)");
            $stmt->execute([$user['id']]);
            $inserted++;
        }
        echo "✓ Created $inserted wallet(s) for existing users\n\n";
    }
    
    echo "═══════════════════════════════════════════\n";
    echo "✓ All done! EcoWallet is ready to use.\n";
    echo "═══════════════════════════════════════════\n";
    
} catch (Exception $ex) {
    echo "\n✗ Error: " . $ex->getMessage() . "\n\n";
    exit(1);
}
?>
