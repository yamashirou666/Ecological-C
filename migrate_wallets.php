<?php
/**
 * EcoCycle — Migration script: Create wallets for existing users.
 * Run this after deploying the EcoWallet feature to migrate existing users.
 */

require_once __DIR__ . '/config/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'This script must be run from the command line.';
    exit(1);
}

try {
    $pdo = db();
    
    echo "EcoCycle Wallet Migration\n";
    echo "=========================\n\n";
    
    // Find all users without wallets
    $stmt = $pdo->prepare('SELECT id, name FROM users WHERE id NOT IN (SELECT DISTINCT user_id FROM wallets)');
    $stmt->execute();
    $usersWithoutWallets = $stmt->fetchAll();
    
    if (!$usersWithoutWallets) {
        echo "✓ All users already have wallets. Nothing to migrate.\n";
        exit(0);
    }
    
    echo "Found " . count($usersWithoutWallets) . " user(s) without wallets.\n";
    echo "Creating wallets...\n\n";
    
    $created = 0;
    foreach ($usersWithoutWallets as $user) {
        $insertStmt = $pdo->prepare('INSERT INTO wallets (user_id) VALUES (?)');
        $insertStmt->execute([$user['id']]);
        $created++;
        echo "✓ Created wallet for: {$user['name']} (ID: {$user['id']})\n";
    }
    
    echo "\n✓ Migration complete! Created $created wallet(s).\n";
    
} catch (Exception $ex) {
    echo "✗ Migration failed: " . $ex->getMessage() . "\n";
    exit(1);
}
?>
