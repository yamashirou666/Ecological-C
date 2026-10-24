<?php
/**
 * Add adjustment entries to the EcoWallet transaction ledger type.
 *
 * Run from the project directory: php migrate_wallet_adjustment_type.php
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
        'SELECT COLUMN_TYPE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?'
    );
    $column->execute(['transactions', 'type']);
    $columnType = $column->fetchColumn();

    if ($columnType === false) {
        throw new RuntimeException('transactions.type was not found.');
    }
    if (!preg_match("/^enum\\((.*)\\)$/i", (string) $columnType, $matches)) {
        throw new RuntimeException('transactions.type is not an ENUM column.');
    }

    $existingTypes = str_getcsv($matches[1], ',', "'");
    foreach (['deposit', 'withdrawal', 'redemption'] as $requiredType) {
        if (!in_array($requiredType, $existingTypes, true)) {
            throw new RuntimeException('transactions.type is missing required value: ' . $requiredType);
        }
    }

    if (in_array('adjustment', $existingTypes, true)) {
        echo "[--] transactions.type already supports adjustment.\n";
    } else {
        $existingTypes[] = 'adjustment';
        $enumValues = implode(',', array_map(
            static fn (string $value): string => $pdo->quote($value),
            $existingTypes
        ));
        $pdo->exec(
            "ALTER TABLE transactions MODIFY type ENUM($enumValues) NOT NULL"
        );
        echo "[OK] Added adjustment to transactions.type.\n";
    }

    echo "Migration complete. Existing transaction rows were not changed.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "[ERROR] Migration failed: " . $ex->getMessage() . "\n");
    exit(1);
}
