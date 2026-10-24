<?php
/**
 * Reconcile the specifically approved wallet balance discrepancies.
 *
 * Usage:
 *   php reconcile_balances.php [--dry-run|--apply]
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
if (count($arguments) > 1 || ($arguments && !in_array($arguments[0], ['--dry-run', '--apply'], true))) {
    fwrite(STDERR, "Usage: php reconcile_balances.php [--dry-run|--apply]\n");
    exit(2);
}

$apply = $arguments === ['--apply'];

$fixedTargets = [
    10 => [
        'before' => ['user_balance' => 9700, 'wallet_balance' => 10400, 'lifetime_earned' => 18400, 'lifetime_redeemed' => 8000],
        'after' => ['user_balance' => 8200, 'wallet_balance' => 8200, 'lifetime_earned' => 18400, 'lifetime_redeemed' => 10200],
    ],
    11 => [
        'before' => ['user_balance' => 700, 'wallet_balance' => 3000, 'lifetime_earned' => 6000, 'lifetime_redeemed' => 3000],
        'after' => ['user_balance' => 700, 'wallet_balance' => 700, 'lifetime_earned' => 6000, 'lifetime_redeemed' => 5300],
    ],
    15 => [
        'before' => ['user_balance' => 3020, 'wallet_balance' => 4520, 'lifetime_earned' => 4520, 'lifetime_redeemed' => 0],
        'after' => ['user_balance' => 3020, 'wallet_balance' => 3020, 'lifetime_earned' => 4520, 'lifetime_redeemed' => 1500],
    ],
];

$demoTargets = [
    3 => ['user_balance' => 187, 'wallet_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0],
    4 => ['user_balance' => 226, 'wallet_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0],
    5 => ['user_balance' => 172, 'wallet_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0],
    6 => ['user_balance' => 84, 'wallet_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0],
    7 => ['user_balance' => 95, 'wallet_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0],
    8 => ['user_balance' => 224, 'wallet_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0],
];

$unchangedUserIds = [9, 16, 17, 18];

function reconciliationSnapshot(PDO $pdo, int $userId, bool $lockRows): array
{
    $lockClause = $lockRows ? ' FOR UPDATE' : '';
    $userStmt = $pdo->prepare('SELECT id, name, points_balance FROM users WHERE id = ?' . $lockClause);
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        throw new RuntimeException("User $userId was not found.");
    }

    $walletStmt = $pdo->prepare(
        'SELECT id, balance, lifetime_earned, lifetime_redeemed
           FROM wallets
          WHERE user_id = ?' . $lockClause
    );
    $walletStmt->execute([$userId]);
    $wallet = $walletStmt->fetch();
    if (!$wallet) {
        throw new RuntimeException("User $userId has no wallet; refusing to reconcile without a locked wallet row.");
    }

    return [
        'user_id' => $userId,
        'name' => (string) $user['name'],
        'wallet_id' => (int) $wallet['id'],
        'user_balance' => (int) $user['points_balance'],
        'wallet_balance' => (int) $wallet['balance'],
        'lifetime_earned' => (int) $wallet['lifetime_earned'],
        'lifetime_redeemed' => (int) $wallet['lifetime_redeemed'],
    ];
}

function reconciliationValues(array $snapshot): array
{
    return [
        'user_balance' => $snapshot['user_balance'],
        'wallet_balance' => $snapshot['wallet_balance'],
        'lifetime_earned' => $snapshot['lifetime_earned'],
        'lifetime_redeemed' => $snapshot['lifetime_redeemed'],
    ];
}

function formatReconciliationValues(array $values): string
{
    return sprintf(
        'users.points_balance=%d, wallets.balance=%d, lifetime_earned=%d, lifetime_redeemed=%d',
        $values['user_balance'],
        $values['wallet_balance'],
        $values['lifetime_earned'],
        $values['lifetime_redeemed']
    );
}

function adjustmentTypeAvailable(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?'
    );
    $stmt->execute(['transactions', 'type']);
    $columnType = $stmt->fetchColumn();
    return is_string($columnType)
        && preg_match("/^enum\\((.*)\\)$/i", $columnType, $matches) === 1
        && in_array('adjustment', str_getcsv($matches[1], ',', "'"), true);
}

function printFinalMismatchCheck(PDO $pdo, ?array $projectedTargets = null): int
{
    echo $projectedTargets === null
        ? "\nFinal balance mismatch check (database after apply):\n"
        : "\nProjected final balance mismatch check (dry run; database unchanged):\n";
    $stmt = $pdo->query(
        'SELECT u.id, u.name, u.points_balance, w.balance AS wallet_balance
           FROM users u
           LEFT JOIN wallets w ON w.user_id = u.id
          ORDER BY u.id'
    );
    $mismatches = [];
    foreach ($stmt->fetchAll() as $row) {
        $userId = (int) $row['id'];
        $userBalance = (int) $row['points_balance'];
        $walletBalance = $row['wallet_balance'] === null ? null : (int) $row['wallet_balance'];
        if (isset($projectedTargets[$userId])) {
            $userBalance = $projectedTargets[$userId]['user_balance'];
            $walletBalance = $projectedTargets[$userId]['wallet_balance'];
        }
        if ($walletBalance === null || $userBalance !== $walletBalance) {
            $mismatches[] = [
                'id' => $userId,
                'name' => (string) $row['name'],
                'user_balance' => $userBalance,
                'wallet_balance' => $walletBalance,
            ];
        }
    }
    if (!$mismatches) {
        echo "  None. All users have matching balances.\n";
        return 0;
    }

    foreach ($mismatches as $row) {
        printf(
            "  User %d (%s): users.points_balance=%d, wallets.balance=%s\n",
            $row['id'],
            $row['name'],
            $row['user_balance'],
            $row['wallet_balance'] === null ? 'MISSING' : (string) $row['wallet_balance']
        );
    }
    return count($mismatches);
}

try {
    $pdo = db();
    echo $apply ? "Mode: APPLY\n" : "Mode: DRY RUN (default; no data will be changed)\n";

    if ($apply && !adjustmentTypeAvailable($pdo)) {
        throw new RuntimeException(
            'The transactions.type enum does not support adjustment. Run migrate_wallet_adjustment_type.php first.'
        );
    }

    $targets = [];
    foreach ($demoTargets as $userId => $before) {
        $targets[$userId] = [
            'before' => $before,
            'after' => [
                'user_balance' => $before['user_balance'],
                'wallet_balance' => $before['user_balance'],
                'lifetime_earned' => $before['lifetime_earned'] + $before['user_balance'],
                'lifetime_redeemed' => $before['lifetime_redeemed'],
            ],
        ];
    }
    $targets += $fixedTargets;
    ksort($targets);

    foreach ($targets as $userId => $target) {
        if ($apply) {
            $pdo->beginTransaction();
        }
        try {
            $snapshot = reconciliationSnapshot($pdo, (int) $userId, $apply);
            $current = reconciliationValues($snapshot);

            if ($current === $target['after']) {
                printf(
                    "User %d (%s): already reconciled; no change. %s\n",
                    (int) $userId,
                    $snapshot['name'],
                    formatReconciliationValues($current)
                );
                if ($apply) {
                    $pdo->commit();
                }
                continue;
            }
            if ($current !== $target['before']) {
                throw new RuntimeException(
                    'User ' . $userId . ' does not match the approved before-state; refusing to overwrite. Current: '
                    . formatReconciliationValues($current)
                );
            }

            printf(
                "User %d (%s):\n  Before: %s\n  After:  %s\n",
                (int) $userId,
                $snapshot['name'],
                formatReconciliationValues($current),
                formatReconciliationValues($target['after'])
            );
            $balanceChange = $target['after']['wallet_balance'] - $snapshot['wallet_balance'];
            printf(
                "  Planned adjustment: users.points_balance %s%d, wallets.balance %s%d, lifetime_earned %s%d, lifetime_redeemed %s%d; one adjustment ledger entry for %d points.\n",
                $target['after']['user_balance'] - $snapshot['user_balance'] >= 0 ? '+' : '',
                $target['after']['user_balance'] - $snapshot['user_balance'],
                $balanceChange >= 0 ? '+' : '',
                $balanceChange,
                $target['after']['lifetime_earned'] - $snapshot['lifetime_earned'] >= 0 ? '+' : '',
                $target['after']['lifetime_earned'] - $snapshot['lifetime_earned'],
                $target['after']['lifetime_redeemed'] - $snapshot['lifetime_redeemed'] >= 0 ? '+' : '',
                $target['after']['lifetime_redeemed'] - $snapshot['lifetime_redeemed'],
                abs($balanceChange)
            );

            if ($apply) {
                if ($balanceChange === 0) {
                    throw new RuntimeException(
                        'A ledger adjustment cannot represent a zero wallet-balance change for user ' . $userId . '.'
                    );
                }

                $description = 'One-time approved balance reconciliation for user ' . $userId;
                $receipt = json_encode([
                    'type' => 'adjustment',
                    'reason' => $description,
                    'before' => [
                        'users.points_balance' => $snapshot['user_balance'],
                        'wallets.balance' => $snapshot['wallet_balance'],
                        'wallets.lifetime_earned' => $snapshot['lifetime_earned'],
                        'wallets.lifetime_redeemed' => $snapshot['lifetime_redeemed'],
                    ],
                    'after' => [
                        'users.points_balance' => $target['after']['user_balance'],
                        'wallets.balance' => $target['after']['wallet_balance'],
                        'wallets.lifetime_earned' => $target['after']['lifetime_earned'],
                        'wallets.lifetime_redeemed' => $target['after']['lifetime_redeemed'],
                    ],
                    'balance_change' => $balanceChange,
                    'timestamp' => date('Y-m-d H:i:s'),
                ], JSON_THROW_ON_ERROR);

                $updateUser = $pdo->prepare('UPDATE users SET points_balance = ? WHERE id = ?');
                $updateUser->execute([$target['after']['user_balance'], $userId]);
                $updateWallet = $pdo->prepare(
                    'UPDATE wallets
                        SET balance = ?, lifetime_earned = ?, lifetime_redeemed = ?
                      WHERE id = ?'
                );
                $updateWallet->execute([
                    $target['after']['wallet_balance'],
                    $target['after']['lifetime_earned'],
                    $target['after']['lifetime_redeemed'],
                    $snapshot['wallet_id'],
                ]);

                $transaction = $pdo->prepare(
                    'INSERT INTO transactions
                        (wallet_id, type, amount, source_description, status, reference_id, balance_after, receipt_data)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $transaction->execute([
                    $snapshot['wallet_id'],
                    'adjustment',
                    abs($balanceChange),
                    $description,
                    'completed',
                    'ADJ-' . strtoupper(bin2hex(random_bytes(6))),
                    $target['after']['wallet_balance'],
                    $receipt,
                ]);
                $pdo->commit();
                echo "  Applied one adjustment ledger entry.\n";
            } else {
                echo "  Dry run only; no data changed.\n";
            }
        } catch (Throwable $ex) {
            if ($apply && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }

    foreach ($unchangedUserIds as $userId) {
        $snapshot = reconciliationSnapshot($pdo, $userId, false);
        $values = reconciliationValues($snapshot);
        printf(
            "User %d (%s): no change requested.\n  Before: %s\n  After:  %s\n",
            $userId,
            $snapshot['name'],
            formatReconciliationValues($values),
            formatReconciliationValues($values)
        );
    }

    if (!$apply) {
        printFinalMismatchCheck($pdo);
    }
    $projectedBalances = [];
    if (!$apply) {
        foreach ($targets as $userId => $target) {
            $projectedBalances[(int) $userId] = [
                'user_balance' => $target['after']['user_balance'],
                'wallet_balance' => $target['after']['wallet_balance'],
            ];
        }
    }
    $mismatchCount = printFinalMismatchCheck($pdo, $apply ? null : $projectedBalances);
    if ($mismatchCount > 0) {
        exit(1);
    }
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\n[ERROR] Reconciliation stopped: " . $ex->getMessage() . "\n");
    if (isset($pdo) && $pdo instanceof PDO) {
        printFinalMismatchCheck($pdo);
    }
    exit(1);
}
