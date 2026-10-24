<?php
/**
 * Create dedicated EcoCycle accounts for a school-defense demo.
 *
 * Run with `php seed_defense_demo.php`; use `--reset` to replace only accounts
 * marked as created by this script.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This seeder must be run from the command line.\n");
}

$arguments = array_slice($argv, 1);
if (count($arguments) > 1 || ($arguments && $arguments[0] !== '--reset')) {
    fwrite(STDERR, "Usage: php seed_defense_demo.php [--reset]\n");
    exit(2);
}
$reset = $arguments === ['--reset'];

require_once __DIR__ . '/includes/functions.php';

const DEFENSE_DEMO_MARKER = 'seed_defense_demo_v1';

$accounts = [
    ['username' => 'demoadmin', 'email' => 'demoadmin@example.com', 'admin' => 1, 'points' => 0],
    ['username' => 'demouser1', 'email' => 'demouser1@example.com', 'admin' => 0, 'points' => 3000],
    ['username' => 'demouser2', 'email' => 'demouser2@example.com', 'admin' => 0, 'points' => 600],
];

$makePassword = static function (): string {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $password = 'A1a';
    for ($index = 0; $index < 17; $index++) {
        $password .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $password;
};

$makePin = static function (): string {
    do {
        $pin = (string) random_int(100000, 999999);
    } while (isTrivialWalletPin($pin));
    return $pin;
};

$credentials = [];
$pdo = db();
$pdo->beginTransaction();

try {
    $findAccount = $pdo->prepare(
        'SELECT id, username, email, referral_code_used
           FROM users
          WHERE username = ? OR email = ?
          FOR UPDATE'
    );
    $deleteAccount = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $insertAccount = $pdo->prepare(
        'INSERT INTO users
            (name, username, email, password_hash, birthdate, neighborhood,
             email_verified_at, is_admin, must_change_password, referral_code_used)
         VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, 0, ?)'
    );
    $updateAccount = $pdo->prepare(
        'UPDATE users
            SET name = ?, password_hash = ?, birthdate = ?, neighborhood = ?,
                email_verified_at = CURRENT_TIMESTAMP, is_admin = ?,
                must_change_password = 0
          WHERE id = ?'
    );
    $walletLookup = $pdo->prepare('SELECT id FROM wallets WHERE user_id = ? FOR UPDATE');
    $startingDepositLookup = $pdo->prepare(
        "SELECT id FROM transactions
          WHERE wallet_id = ? AND type = 'deposit' AND source_description = ?
          LIMIT 1
          FOR UPDATE"
    );

    foreach ($accounts as $account) {
        $findAccount->execute([$account['username'], $account['email']]);
        $matches = $findAccount->fetchAll();
        $existing = null;

        if ($matches) {
            if (count($matches) !== 1
                || $matches[0]['username'] !== $account['username']
                || strcasecmp($matches[0]['email'], $account['email']) !== 0
                || $matches[0]['referral_code_used'] !== DEFENSE_DEMO_MARKER) {
                throw new RuntimeException(
                    'A defense-demo username or email is already owned by an account this script did not create.'
                );
            }
            $existing = $matches[0];
        }

        if ($reset && $existing !== null) {
            $deleteAccount->execute([(int) $existing['id']]);
            $existing = null;
        }

        $password = $makePassword();
        $pin = $makePin();
        $birthdate = '1990-01-01';

        if ($existing === null) {
            $insertAccount->execute([
                $account['username'],
                $account['username'],
                $account['email'],
                hashPassword($password),
                $birthdate,
                'Greendale',
                $account['admin'],
                DEFENSE_DEMO_MARKER,
            ]);
            $userId = (int) $pdo->lastInsertId();
        } else {
            $userId = (int) $existing['id'];
            $updateAccount->execute([
                $account['username'],
                hashPassword($password),
                $birthdate,
                'Greendale',
                $account['admin'],
                $userId,
            ]);
        }

        $walletLookup->execute([$userId]);
        if (!$walletLookup->fetchColumn()) {
            createWallet($userId);
        }
        setWalletPin($userId, $pin);

        if ($account['points'] > 0) {
            $walletLookup->execute([$userId]);
            $walletId = (int) $walletLookup->fetchColumn();
            $startingDepositLookup->execute([$walletId, 'Demo starting balance']);
            if (!$startingDepositLookup->fetchColumn()) {
                $balanceStmt = $pdo->prepare(
                    'SELECT u.points_balance, w.balance
                       FROM users u
                       JOIN wallets w ON w.user_id = u.id
                      WHERE u.id = ?
                      FOR UPDATE'
                );
                $balanceStmt->execute([$userId]);
                $balances = $balanceStmt->fetch();
                if (!$balances || (int) $balances['points_balance'] !== (int) $balances['balance']) {
                    throw new RuntimeException(
                        'A defense-demo account has mismatched balances; no starting deposit was applied.'
                    );
                }
                if ((int) $balances['points_balance'] !== 0) {
                    throw new RuntimeException(
                        'A defense-demo account has points but no starting-balance ledger entry.'
                    );
                }
                adjustPoints(
                    $userId,
                    $account['points'],
                    'deposit',
                    'Demo starting balance',
                    ['source' => 'Demo starting balance'],
                    'earned'
                );
            }
        }

        $credentials[] = [
            'username' => $account['username'],
            'password' => $password,
            'pin' => $pin,
        ];
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

$driftStmt = $pdo->query(
    'SELECT u.id, u.username, u.points_balance, w.balance AS wallet_balance
       FROM users u
       LEFT JOIN wallets w ON w.user_id = u.id
      WHERE w.id IS NULL OR u.points_balance <> w.balance
      ORDER BY u.id'
);
$drift = $driftStmt->fetchAll();

if (!$drift) {
    echo "Balance-drift check: PASS (all users have a wallet and matching balances).\n";
} else {
    echo "Balance-drift check: FAIL (" . count($drift) . " account(s) differ or lack a wallet).\n";
    foreach ($drift as $row) {
        printf(
            "  user_id=%d username=%s user_balance=%d wallet_balance=%s\n",
            (int) $row['id'],
            $row['username'],
            (int) $row['points_balance'],
            $row['wallet_balance'] === null ? 'missing' : (string) $row['wallet_balance']
        );
    }
}

echo "\nDefense demo credentials (shown once; they are not written to files or logs):\n";
foreach ($credentials as $credential) {
    printf(
        "  %s | password: %s | wallet PIN: %s\n",
        $credential['username'],
        $credential['password'],
        $credential['pin']
    );
}

if ($drift) {
    exit(1);
}
