<?php
/**
 * EcoCycle — EcoWallet: Personal banking-like account for recycling points.
 * Features: Balance, transaction history, PIN-secured withdrawals & redemptions.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = currentUser();
sweepExpiredWithdrawalRequests((int) $user['id']);
$wallet = getUserWallet((int) $user['id']);
$hasPin = hasPin((int) $user['id']);
$isLocked = isWalletLocked((int) $user['id']);
$lockoutRemaining = getWalletLockoutRemaining((int) $user['id']);

// Handle withdrawal request
$errors = [];
$success = [];
$showWithdrawalForm = false;
$showPinSetup = false;
$savedPayoutMethods = getSavedPayoutMethods((int) $user['id']);
$legacyWithdrawalToken = WALLET_CHECKOUT_ENABLED ? '' : getWithdrawalIdempotencyToken('legacy');
$dailyWithdrawalTotal = getDailyWithdrawalTotal((int) $user['id']);
$withdrawalRemainingQuota = max(0, WALLET_DAILY_WITHDRAWAL_CAP - $dailyWithdrawalTotal);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($isLocked) {
        $errors[] = sprintf('Wallet locked. Try again in %d seconds.', $lockoutRemaining);
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'setup_pin') {
            $pin1 = $_POST['pin1'] ?? '';
            $pin2 = $_POST['pin2'] ?? '';

            if (!preg_match('/^\d{4,6}$/', $pin1)) {
                $errors[] = 'PIN must be 4-6 digits.';
            } elseif ($pin1 !== $pin2) {
                $errors[] = 'PINs do not match.';
            } else {
                try {
                    setWalletPin((int) $user['id'], $pin1);
                    sendWalletNotification((int) $user['id'], 'pin_setup', []);
                    $success[] = '✓ PIN set successfully! Your wallet is now secured.';
                    $hasPin = true;
                } catch (Exception $ex) {
                    $errors[] = 'Failed to set PIN: ' . $ex->getMessage();
                }
            }
        } elseif ($action === 'withdraw' && !WALLET_CHECKOUT_ENABLED) {
            if (!$hasPin) {
                $showPinSetup = true;
                $errors[] = 'Please set up a PIN first.';
            } else {
                $amount = (int) ($_POST['withdraw_amount'] ?? 0);
                $method = $_POST['payout_method'] ?? 'voucher';
                $pinAttempt = $_POST['pin_attempt'] ?? '';
                $details = [
                    'provider' => trim((string) ($_POST['payout_provider'] ?? '')),
                    'account' => trim((string) ($_POST['payout_account'] ?? '')),
                    'label' => trim((string) ($_POST['payout_label'] ?? '')),
                ];
                $savedMethodId = (int) ($_POST['saved_method_id'] ?? 0);
                $saveMethod = !empty($_POST['save_payout_method']);
                if (!isWithdrawalIdempotencyTokenValid('legacy', (string) ($_POST['idempotency_token'] ?? ''))) {
                    $errors[] = 'This withdrawal form has already been submitted or expired. Refresh and try again.';
                }

                if ($savedMethodId > 0) {
                    $savedStmt = db()->prepare('SELECT method, provider, account_label, account_last4 FROM saved_payout_methods WHERE id = ? AND user_id = ?');
                    $savedStmt->execute([$savedMethodId, $user['id']]);
                    $saved = $savedStmt->fetch();
                    if (!$saved || $saved['method'] !== $method) {
                        $errors[] = 'The selected saved payout method is invalid.';
                    } else {
                        $details = ['provider' => $saved['provider'], 'account' => 'saved:' . $saved['account_last4'], 'label' => $saved['account_label']];
                        $saveMethod = false;
                    }
                }

                if ($errors) {
                    // Stop immediately when a saved payout method failed ownership validation.
                } elseif (empty($_POST['confirmed_withdrawal'])) {
                    $errors[] = 'Please review and confirm the withdrawal details first.';
                } elseif ($amount < WALLET_MIN_WITHDRAWAL) {
                    $errors[] = 'Minimum withdrawal is ' . num(WALLET_MIN_WITHDRAWAL) . ' points.';
                } elseif ($amount > (int) $wallet['balance']) {
                    $errors[] = 'Amount exceeds your current balance.';
                } elseif ($amount > $withdrawalRemainingQuota) {
                    $errors[] = 'This amount exceeds your remaining daily withdrawal quota.';
                } elseif (!preg_match('/^\d{4,6}$/', $pinAttempt)) {
                    $errors[] = 'PIN must be 4-6 numeric digits.';
                } elseif ($savedMethodId === 0 && ($detailErrors = validateWithdrawalDetails($method, $details))) {
                    $errors = array_merge($errors, $detailErrors);
                } elseif (!verifyPin((int) $user['id'], $pinAttempt)) {
                    $attemptWallet = getUserWallet((int) $user['id']);
                    $attempts = (int) ($attemptWallet['failed_pin_attempts'] ?? 0);
                    logWithdrawalAudit((int) $user['id'], 'pin_failed', $amount, $method, null, ['attempts' => $attempts]);
                    $errors[] = $attempts >= 3 ? 'Wallet locked for 15 minutes after too many failed attempts.' : 'Incorrect PIN. ' . (3 - $attempts) . ' attempts remaining.';
                } else {
                    try {
                        $desc = match($method) {
                            'bank_transfer' => 'Bank Transfer Withdrawal',
                            'ewallet' => 'E-Wallet Withdrawal',
                            default => 'Voucher / Gift Card Withdrawal'
                        };
                        $txn = createWithdrawal((int) $user['id'], $amount, $method, $desc, $details, $saveMethod);
                        unset($_SESSION['wallet_withdrawal_idempotency']);
                        logWithdrawalAudit((int) $user['id'], 'withdrawal_completed', $amount, $method, (int) $txn['id'], ['provider' => $details['provider'], 'account_last4' => substr($details['account'], -4)]);
                        sendWalletNotification((int) $user['id'], 'withdrawal', [
                            'amount' => $amount,
                            'method' => $method,
                            'reference_id' => $txn['reference_id'],
                            'status' => 'completed',
                            'new_balance' => $txn['balance_after']
                        ]);
                        $success[] = sprintf('✓ Withdrawal processed! Reference: %s', $txn['reference_id']);
                        // Refresh wallet data
                        $wallet = getUserWallet((int) $user['id']);
                        $dailyWithdrawalTotal = getDailyWithdrawalTotal((int) $user['id']);
                        $withdrawalRemainingQuota = max(0, WALLET_DAILY_WITHDRAWAL_CAP - $dailyWithdrawalTotal);
                    } catch (Exception $ex) {
                        logWithdrawalAudit((int) $user['id'], 'withdrawal_failed', $amount, $method, null, ['error' => $ex->getMessage()]);
                        $errors[] = 'Withdrawal failed: ' . $ex->getMessage();
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && WALLET_CHECKOUT_ENABLED
    && ($_POST['action'] ?? '') === 'withdraw' && !$errors) {
    $errors[] = 'Use the checkout page to start a withdrawal.';
}

// Transaction filtering
$filterType = $_GET['filter_type'] ?? '';
$filterSearch = $_GET['search'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$transactions = getWalletTransactions((int) $user['id'], $filterType ?: null, $filterSearch ?: null, $limit + 1, $offset);
$hasMore = count($transactions) > $limit;
$transactions = array_slice($transactions, 0, $limit);

$pageTitle = 'EcoWallet — Personal Banking';
require __DIR__ . '/includes/header.php';
?>

<main id="main" class="flex-1 max-w-6xl mx-auto px-4 py-8 w-full">
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-slate-900 mb-2">🏦 EcoWallet</h1>
        <p class="text-slate-600">Your personal banking account for EcoCycle points. Deposits are automatic, withdrawals are secure.</p>
    </div>

    <!-- Error / Success Messages -->
    <?php if ($errors): ?>
        <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm" role="alert">
            <ul class="space-y-1">
                <?php foreach ($errors as $err): ?><li>• <?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="mb-6 bg-green-50 border border-green-200 text-green-700 rounded-xl px-4 py-3 text-sm" role="alert">
            <ul class="space-y-1">
                <?php foreach ($success as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Wallet Balance Card (Banking-style hero) -->
    <div class="wallet-shell mb-8 p-8 text-white">
        <div class="relative z-10 flex justify-between items-start mb-12">
            <div>
                <span class="wallet-pill">EcoWallet</span>
                <p class="text-emerald-100 text-sm font-semibold uppercase tracking-[0.18em] mt-4">Current Balance</p>
                <p class="text-5xl font-extrabold mt-2"><?= num($wallet['balance']) ?> pts</p>
            </div>
            <div class="wallet-stat px-4 py-3 min-w-[170px]">
                <p class="text-xs text-emerald-100 uppercase tracking-[0.18em]">Security</p>
                <p class="text-lg font-bold mt-1"><?= e($hasPin ? '🔒 Secured' : '🔓 Setup PIN') ?></p>
            </div>
        </div>

        <div class="relative z-10 grid grid-cols-3 gap-4 mb-8 border-t border-white/20 pt-6">
            <div class="wallet-stat">
                <p class="text-emerald-100 text-xs uppercase tracking-[0.18em]">Lifetime Earned</p>
                <p class="text-2xl font-bold mt-1"><?= num($wallet['lifetime_earned']) ?></p>
            </div>
            <div class="wallet-stat">
                <p class="text-emerald-100 text-xs uppercase tracking-[0.18em]">Lifetime Redeemed</p>
                <p class="text-2xl font-bold mt-1"><?= num($wallet['lifetime_redeemed']) ?></p>
            </div>
            <div class="wallet-stat">
                <p class="text-emerald-100 text-xs uppercase tracking-[0.18em]">Member Since</p>
                <p class="text-sm font-bold mt-1"><?= date('M Y', strtotime($wallet['created_at'])) ?></p>
            </div>
        </div>

        <!-- Wallet Status Alert (if locked) -->
        <?php if ($isLocked): ?>
            <div class="bg-red-500/20 border border-red-300 rounded-lg px-4 py-3 text-sm mb-6">
                <p class="font-semibold">⚠ Wallet Temporarily Locked</p>
                <p class="text-emerald-50">Too many failed PIN attempts. Please try again in <?= e($lockoutRemaining) ?> seconds.</p>
            </div>
        <?php endif; ?>

        <!-- Quick Action Buttons -->
        <div class="relative z-10 flex flex-wrap gap-3">
            <button onclick="document.getElementById('pinSetupModal').showModal()" class="wallet-action bg-white text-emerald-700 hover:bg-emerald-50 <?= e($hasPin ? 'opacity-50 cursor-not-allowed' : '') ?>" <?= e($hasPin ? 'disabled' : '') ?>>
                <?= e($hasPin ? '✓ PIN Set' : '🔐 Set PIN') ?>
            </button>
            <?php if (WALLET_CHECKOUT_ENABLED): ?>
                <?php if ($hasPin && !$isLocked): ?>
                    <a href="withdrawal_checkout.php?step=start" class="wallet-action bg-emerald-500 hover:bg-emerald-400 text-white inline-block">💰 Withdraw Points</a>
                <?php else: ?>
                    <span class="wallet-action bg-emerald-500 text-white opacity-60 cursor-not-allowed" aria-disabled="true" title="<?= e($isLocked ? 'Wallet temporarily locked' : 'Set up a PIN to withdraw') ?>">💰 Withdraw Points</span>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($hasPin && !$isLocked && (int) $wallet['balance'] >= WALLET_MIN_WITHDRAWAL): ?>
                    <button onclick="document.getElementById('withdrawalModal').showModal()" class="wallet-action bg-emerald-500 hover:bg-emerald-400 text-white">
                        💰 Withdraw Points
                    </button>
                <?php else: ?>
                    <?php
                    $withdrawDisabledReason = (int) $wallet['balance'] < WALLET_MIN_WITHDRAWAL
                        ? 'You need at least ' . num(WALLET_MIN_WITHDRAWAL) . ' points to withdraw'
                        : ($isLocked ? 'Wallet temporarily locked' : 'Set up a PIN to withdraw');
                    ?>
                    <span class="wallet-action bg-emerald-500 text-white opacity-60 cursor-not-allowed" aria-disabled="true" title="<?= e($withdrawDisabledReason) ?>">💰 Withdraw Points</span>
                <?php endif; ?>
            <?php endif; ?>
            <a href="rewards.php" class="wallet-action bg-emerald-400 hover:bg-emerald-300 text-emerald-900 inline-block">
                🎁 Redeem Rewards
            </a>
        </div>
    </div>

    <!-- PIN Setup Modal -->
    <dialog id="pinSetupModal" class="rounded-2xl shadow-2xl backdrop:bg-black/50 p-0 max-w-md">
        <form method="post" class="p-8">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="setup_pin">
            
            <h2 class="text-2xl font-bold text-slate-900 mb-2">🔐 Set Your PIN</h2>
            <p class="text-slate-600 text-sm mb-6">Create a 4-6 digit PIN to secure your wallet. Required for withdrawals and redemptions.</p>

            <div class="mb-4">
                <label for="pin1" class="block text-sm font-semibold text-slate-700 mb-2">PIN (4-6 digits)</label>
                <input type="password" id="pin1" name="pin1" maxlength="6" inputmode="numeric" placeholder="••••" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 text-center text-2xl tracking-widest" required>
            </div>

            <div class="mb-6">
                <label for="pin2" class="block text-sm font-semibold text-slate-700 mb-2">Confirm PIN</label>
                <input type="password" id="pin2" name="pin2" maxlength="6" inputmode="numeric" placeholder="••••" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 text-center text-2xl tracking-widest" required>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg transition">
                    Set PIN
                </button>
                <button type="button" onclick="document.getElementById('pinSetupModal').close()" class="wallet-secondary-button flex-1 font-bold py-3 rounded-lg transition">
                    Cancel
                </button>
            </div>
        </form>
    </dialog>

    <?php if (!WALLET_CHECKOUT_ENABLED): ?>
    <!-- Legacy withdrawal modal, retained for the checkout feature flag. -->
    <dialog id="withdrawalModal" class="rounded-2xl shadow-2xl backdrop:bg-black/50 p-0 max-w-lg w-[calc(100%-2rem)]">
        <form method="post" class="p-8">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="withdraw">
            <input type="hidden" name="idempotency_token" value="<?= e($legacyWithdrawalToken) ?>">
            <input type="hidden" name="confirmed_withdrawal" id="confirmed_withdrawal" value="0">
            
            <h2 class="text-2xl font-bold text-slate-900 mb-2">💰 Withdraw Points</h2>
            <p class="text-slate-600 text-sm mb-6">Minimum <?= num(WALLET_MIN_WITHDRAWAL) ?> points. Your remaining daily quota is <?= num($withdrawalRemainingQuota) ?> points.</p>

            <div class="mb-4">
                <label for="withdraw_amount" class="block text-sm font-semibold text-slate-700 mb-2">Amount (points)</label>
                <div class="flex items-center gap-2">
                    <input type="number" id="withdraw_amount" name="withdraw_amount" min="<?= e(WALLET_MIN_WITHDRAWAL) ?>" max="<?= e($wallet['balance']) ?>" placeholder="<?= e(WALLET_MIN_WITHDRAWAL) ?>" class="flex-1 px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500" required>
                    <span class="text-xs text-slate-600 font-semibold">max <?= num($wallet['balance']) ?></span>
                </div>
                <div class="mt-2 flex flex-wrap gap-2" id="quickAmounts">
                    <?php foreach ([25, 50, 75] as $percent): ?>
                        <button type="button" data-percent="<?= e($percent) ?>" class="rounded-lg border border-emerald-200 px-3 py-1 text-xs font-bold text-emerald-700"> <?= e($percent) ?>% </button>
                    <?php endforeach; ?>
                    <button type="button" data-percent="100" class="rounded-lg border border-emerald-200 px-3 py-1 text-xs font-bold text-emerald-700">Max</button>
                </div>
                <p id="withdrawAmountError" class="mt-2 text-xs font-semibold text-red-600" role="alert"></p>
                <p id="withdrawPreview" class="mt-2 text-sm font-semibold text-emerald-700"></p>
            </div>

            <div class="mb-4">
                <label for="payout_method" class="block text-sm font-semibold text-slate-700 mb-2">Payout Method</label>
                <select name="payout_method" id="payout_method" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <option value="voucher">🎁 Gift Card / Voucher</option>
                    <option value="ewallet">📱 E-Wallet (GCash / PayPal)</option>
                    <option value="bank_transfer">🏦 Bank Transfer</option>
                </select>
                <p id="processingTime" class="text-xs text-slate-600 mt-2"></p>
            </div>

            <div id="savedMethodGroup" class="mb-4 <?= e($savedPayoutMethods ? '' : 'hidden') ?>">
                <label for="saved_method" class="block text-sm font-semibold text-slate-700 mb-2">Use a saved payout method</label>
                <select id="saved_method" class="w-full px-4 py-3 border border-slate-300 rounded-lg">
                    <option value="">Enter a new method</option>
                    <?php foreach ($savedPayoutMethods as $saved): ?>
                        <option value="<?= (int) $saved['id'] ?>" data-method="<?= e($saved['method']) ?>" data-provider="<?= e($saved['provider']) ?>" data-last4="<?= e($saved['account_last4']) ?>"><?= e($saved['account_label']) ?> ••••<?= e($saved['account_last4']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="payoutDetails" class="mb-4 hidden">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="payout_provider" id="providerLabel" class="block text-sm font-semibold text-slate-700 mb-2">Provider</label>
                        <input id="payout_provider" name="payout_provider" type="text" class="w-full px-4 py-3 border border-slate-300 rounded-lg" placeholder="GCash or bank name">
                    </div>
                    <div>
                        <label for="payout_account" id="accountLabel" class="block text-sm font-semibold text-slate-700 mb-2">Account</label>
                        <input id="payout_account" name="payout_account" type="text" class="w-full px-4 py-3 border border-slate-300 rounded-lg" placeholder="Mobile number or account number">
                    </div>
                </div>
                <input type="hidden" name="payout_label" id="payout_label" value="">
                <label class="mt-3 flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="save_payout_method" value="1" class="h-4 w-4"> Save this payout method</label>
            </div>

            <div class="mb-6">
                <label for="pin_attempt" class="block text-sm font-semibold text-slate-700 mb-2">Confirm with PIN</label>
                <input type="password" id="pin_attempt" name="pin_attempt" maxlength="6" inputmode="numeric" pattern="\d{4,6}" placeholder="••••" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 text-center text-2xl tracking-widest" required>
                <p class="text-xs text-slate-600 mt-2">Enter your 4-6 digit PIN to confirm withdrawal. <a href="pin_reset.php" class="text-emerald-600 font-semibold">Forgot PIN?</a></p>
            </div>

            <div class="flex gap-3">
                <button type="button" id="reviewWithdrawal" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg transition">
                    Review withdrawal
                </button>
                <button type="button" onclick="document.getElementById('withdrawalModal').close()" class="wallet-secondary-button flex-1 font-bold py-3 rounded-lg transition">
                    Cancel
                </button>
            </div>
        </form>
    </dialog>

    <dialog id="withdrawalConfirmModal" class="rounded-2xl shadow-2xl backdrop:bg-black/50 p-0 max-w-md w-[calc(100%-2rem)]">
        <div class="p-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-2">Review withdrawal</h2>
            <p id="withdrawalSummary" class="text-slate-600 text-sm mb-6"></p>
            <div class="flex gap-3">
                <button type="button" id="confirmWithdrawal" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg">Confirm and enter PIN</button>
                <button type="button" onclick="document.getElementById('withdrawalConfirmModal').close()" class="wallet-secondary-button flex-1 font-bold py-3 rounded-lg">Edit</button>
            </div>
        </div>
    </dialog>
    <?php endif; ?>

    <!-- Transaction History Section -->
    <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold text-slate-900">📊 Transaction History</h2>
            <a href="receipt.php?action=export_csv" class="wallet-secondary-button text-sm font-bold px-4 py-2 rounded-lg transition" aria-label="Export transactions as CSV">
                Export CSV
            </a>
        </div>

        <!-- Filters -->
        <div class="mb-6 flex flex-col md:flex-row gap-4 items-end">
            <div class="flex-1">
                <label for="filterSearch" class="block text-sm font-semibold text-slate-700 mb-2">Search</label>
                <input type="text" id="filterSearch" placeholder="Search descriptions..." class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500" onchange="document.getElementById('filterForm').submit()">
            </div>
            <div class="w-full md:w-auto">
                <label for="filterType" class="block text-sm font-semibold text-slate-700 mb-2">Type</label>
                <select id="filterType" onchange="document.getElementById('filterForm').submit()" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <option value="">All Transactions</option>
                    <option value="deposit" <?= e($filterType === 'deposit' ? 'selected' : '') ?>>Deposits</option>
                    <option value="withdrawal" <?= e($filterType === 'withdrawal' ? 'selected' : '') ?>>Withdrawals</option>
                    <option value="redemption" <?= e($filterType === 'redemption' ? 'selected' : '') ?>>Redemptions</option>
                    <option value="adjustment" <?= e($filterType === 'adjustment' ? 'selected' : '') ?>>Adjustments</option>
                </select>
            </div>
            <form id="filterForm" method="get" class="hidden">
                <input type="hidden" name="filter_type" value="<?= e($filterType) ?>">
                <input type="hidden" name="search" value="<?= e($filterSearch) ?>">
            </form>
        </div>

        <!-- Transactions List -->
        <?php if ($transactions): ?>
            <div class="space-y-3">
                <?php foreach ($transactions as $txn): 
                    $icon = match($txn['type']) {
                        'deposit' => '⬇️',
                        'withdrawal' => '⬆️',
                        'redemption' => '🎁',
                        'adjustment' => '⚖️',
                        default => '•'
                    };
                    $typeLabel = walletTransactionLabel((string) $txn['type']);
                    $balanceChange = walletTransactionBalanceChange($txn);
                    $statusColor = match($txn['status']) {
                        'completed' => 'bg-green-50 text-green-700 border-green-200',
                        'pending' => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                        'failed' => 'bg-red-50 text-red-700 border-red-200',
                        default => 'bg-slate-50 text-slate-700 border-slate-200'
                    };
                    $signClass = $balanceChange >= 0 ? 'text-green-600' : 'text-emerald-600';
                ?>
                    <div class="flex items-center justify-between p-4 border border-slate-200 rounded-lg hover:bg-eco-50 transition">
                        <div class="flex-1">
                            <div class="flex items-center gap-3">
                                <span class="text-2xl"><?= e($icon) ?></span>
                                <div>
                                    <p class="font-semibold text-slate-900"><?= e($txn['source_description']) ?></p>
                                    <p class="text-xs text-slate-600"><?= e($typeLabel) ?></p>
                                    <p class="text-xs text-slate-600"><?= date('M d, Y · H:i', strtotime($txn['created_at'])) ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold <?= e($signClass) ?>">
                                <?= e(($balanceChange >= 0 ? '+' : '−') . num(abs($balanceChange))) ?> pts
                            </p>
                            <div class="mt-2 flex items-center gap-2">
                                <span class="inline-block text-xs font-semibold px-2 py-1 rounded border <?= e($statusColor) ?>">
                                    <?= e(ucfirst($txn['status'])) ?>
                                </span>
                                <a href="receipt.php?action=receipt&amp;txn_id=<?= e($txn['id']) ?>" class="text-xs text-emerald-600 hover:text-emerald-700 font-semibold">
                                    📄 Receipt
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($page > 1 || $hasMore): ?>
                <div class="mt-6 flex justify-between items-center">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= e($page - 1) ?>&amp;filter_type=<?= e($filterType) ?>&amp;search=<?= e($filterSearch) ?>" class="text-emerald-600 hover:text-emerald-700 font-semibold">← Previous</a>
                    <?php else: ?>
                        <span class="text-slate-400">← Previous</span>
                    <?php endif; ?>

                    <span class="text-slate-600 text-sm">Page <?= e($page) ?></span>

                    <?php if ($hasMore): ?>
                        <a href="?page=<?= e($page + 1) ?>&amp;filter_type=<?= e($filterType) ?>&amp;search=<?= e($filterSearch) ?>" class="text-emerald-600 hover:text-emerald-700 font-semibold">Next →</a>
                    <?php else: ?>
                        <span class="text-slate-400">Next →</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-12">
                <p class="text-slate-600">No transactions yet. Earn points by logging recycling to see them here! 🌱</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php if (!WALLET_CHECKOUT_ENABLED): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('#withdrawalModal form');
    const amount = document.getElementById('withdraw_amount');
    const balance = <?= (int) $wallet['balance'] ?>;
    const minAmount = <?= WALLET_MIN_WITHDRAWAL ?>;
    const dailyQuota = <?= (int) $withdrawalRemainingQuota ?>;
    const rate = <?= WALLET_POINTS_PER_PESO ?>;
    const method = document.getElementById('payout_method');
    const details = document.getElementById('payoutDetails');
    const provider = document.getElementById('payout_provider');
    const account = document.getElementById('payout_account');
    const label = document.getElementById('payout_label');
    const saved = document.getElementById('saved_method');
    const savedId = document.createElement('input');
    savedId.type = 'hidden'; savedId.name = 'saved_method_id'; savedId.value = '0'; form.appendChild(savedId);
    const amountError = document.getElementById('withdrawAmountError');
    const preview = document.getElementById('withdrawPreview');
    const processing = document.getElementById('processingTime');
    const confirmModal = document.getElementById('withdrawalConfirmModal');
    const summary = document.getElementById('withdrawalSummary');
    const confirmed = document.getElementById('confirmed_withdrawal');
    const pin = document.getElementById('pin_attempt');
    const review = document.getElementById('reviewWithdrawal');
    const confirm = document.getElementById('confirmWithdrawal');

    function updateMethod() {
        const value = method.value;
        const isVoucher = value === 'voucher';
        details.classList.toggle('hidden', isVoucher);
        processing.textContent = value === 'voucher' ? 'Available immediately.' : (value === 'ewallet' ? 'Estimated processing time: 1 business day.' : 'Estimated processing time: 1-3 business days.');
        document.getElementById('providerLabel').textContent = value === 'bank_transfer' ? 'Bank name' : 'E-wallet provider';
        document.getElementById('accountLabel').textContent = value === 'bank_transfer' ? 'Account number' : 'Mobile number or email';
        provider.placeholder = value === 'bank_transfer' ? 'Bank name' : 'GCash or PayPal';
        account.placeholder = value === 'bank_transfer' ? '6-24 digit account number' : '09xxxxxxxxx or email';
        if (saved) {
            Array.from(saved.options).forEach(option => option.hidden = option.value && option.dataset.method !== value);
            if (saved.value && saved.selectedOptions[0].dataset.method !== value) saved.value = '';
        }
    }

    function updateAmount() {
        const value = Number(amount.value || 0);
        let error = '';
        if (amount.value !== '' && (!Number.isInteger(value) || value < minAmount)) error = 'Minimum withdrawal is ' + minAmount.toLocaleString() + ' pts.';
        else if (value > balance) error = 'Amount exceeds your current balance.';
        else if (value > dailyQuota) error = 'Amount exceeds your remaining daily quota.';
        amountError.textContent = error;
        preview.textContent = error ? '' : 'You will have ' + (balance - value).toLocaleString() + ' pts left · approx. ₱' + (value / rate).toFixed(2);
        review.disabled = Boolean(error);
        return !error;
    }

    method.addEventListener('change', updateMethod);
    amount.addEventListener('input', updateAmount);
    document.querySelectorAll('#quickAmounts [data-percent]').forEach(button => button.addEventListener('click', function () {
        amount.value = Math.floor(balance * Number(button.dataset.percent) / 100);
        updateAmount();
    }));
    if (saved) saved.addEventListener('change', function () {
        const option = saved.selectedOptions[0];
        savedId.value = saved.value || '0';
        if (saved.value) { provider.value = option.dataset.provider; account.value = ''; label.value = option.textContent.trim(); }
    });
    review.addEventListener('click', function () {
        if (!updateAmount()) return;
        if (!method.value) return;
        const target = saved && saved.value ? saved.selectedOptions[0].textContent.trim() : (provider.value + ' ' + account.value.slice(-4).padStart(account.value.length, '*'));
        summary.textContent = 'Withdraw ' + Number(amount.value).toLocaleString() + ' pts via ' + method.options[method.selectedIndex].text + ' to ' + target + '?';
        confirmModal.showModal();
    });
    confirm.addEventListener('click', function () {
        confirmed.value = '1';
        confirmModal.close();
        pin.focus();
    });
    form.addEventListener('submit', function (event) {
        if (confirmed.value !== '1' || !/^\d{4,6}$/.test(pin.value)) {
            event.preventDefault();
            if (confirmed.value !== '1') review.click();
            else amountError.textContent = 'PIN must be 4-6 numeric digits.';
            return;
        }
        const submit = form.querySelector('button[type="submit"]');
        if (submit) { submit.disabled = true; submit.textContent = 'Processing...'; }
    });
    updateMethod(); updateAmount();
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
