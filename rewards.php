<?php
/**
 * EcoCycle — rewards marketplace + redemption flow with PIN-secured checkout.
 * Guests can browse; redeeming requires login, enough points, and PIN verification.
 */
require_once __DIR__ . '/includes/functions.php';

$user   = currentUser();
$filter = $_GET['category'] ?? 'all';
$errors = [];
$success = [];
$showPinModal = false;
$pendingRedemption = null;
$verificationRequired = false;
$emailVerified = !$user || !EMAIL_FEATURES_ENABLED || !empty($user['email_verified_at']);

// Handle a redemption POST with PIN verification.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    if (!verifyCsrf()) {
        setFlash('error', 'Your session expired. Please try again.');
        header('Location: rewards.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'initiate_redemption') {
        // First step: validate reward and prompt for PIN
        $rewardId = (int) ($_POST['reward_id'] ?? 0);
        if (EMAIL_FEATURES_ENABLED && !$emailVerified) {
            $verificationRequired = true;
            $errors[] = 'Verify your email address before redeeming rewards.';
        } else {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT * FROM rewards WHERE id = ?');
            $stmt->execute([$rewardId]);
            $reward = $stmt->fetch();

            $stmt = $pdo->prepare('SELECT points_balance FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $userBalance = (int) $stmt->fetchColumn();

            if (!$reward) {
                $errors[] = 'That reward is no longer available.';
            } elseif ((int) $reward['quantity_available'] <= 0) {
                $errors[] = 'Sorry, that reward is out of stock.';
            } elseif ($userBalance < (int) $reward['points_cost']) {
                $errors[] = 'You need ' . num((int) $reward['points_cost'] - $userBalance) . ' more points to redeem this.';
            } else {
                // Reward is valid - prompt for PIN
                $wallet = getUserWallet((int) $user['id']);
                if (!$wallet || !$wallet['pin_hash']) {
                    $errors[] = 'Please set up a wallet PIN first to redeem rewards.';
                } else {
                    $showPinModal = true;
                    $pendingRedemption = ['reward_id' => $rewardId, 'reward' => $reward];
                }
            }
        }
    } elseif ($action === 'confirm_redemption') {
        // Second step: verify PIN and complete redemption
        $rewardId = (int) ($_POST['reward_id'] ?? 0);
        $pinAttempt = $_POST['pin_attempt'] ?? '';
        
        $wallet = getUserWallet((int) $user['id']);
        $isLocked = isWalletLocked((int) $user['id']);
        
        if ($isLocked) {
            $lockoutRemaining = getWalletLockoutRemaining((int) $user['id']);
            $errors[] = sprintf('Wallet locked due to too many failed PIN attempts. Try again in %d seconds.', $lockoutRemaining);
        } elseif (!verifyPin((int) $user['id'], $pinAttempt)) {
            $errors[] = 'Incorrect PIN. Please try again.';
        } else {
            // PIN verified - proceed with redemption
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $uStmt = $pdo->prepare('SELECT id, points_balance, email_verified_at FROM users WHERE id = ? FOR UPDATE');
                $uStmt->execute([$user['id']]);
                $lockedUser = $uStmt->fetch();
                if (!$lockedUser) {
                    throw new RuntimeException('That account could not be found.');
                }
                if (EMAIL_FEATURES_ENABLED && empty($lockedUser['email_verified_at'])) {
                    $verificationRequired = true;
                    throw new RuntimeException('Email verification is required before reward redemption.');
                }

                $walletStmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
                $walletStmt->execute([$user['id']]);
                $lockedWallet = $walletStmt->fetch();

                $rStmt = $pdo->prepare('SELECT * FROM rewards WHERE id = ? FOR UPDATE');
                $rStmt->execute([$rewardId]);
                $reward = $rStmt->fetch();
                $balance = (int) $lockedUser['points_balance'];

                if (!$reward) {
                    throw new RuntimeException('That reward is no longer available.');
                }
                if (!$lockedWallet) {
                    throw new RuntimeException('Please set up a wallet PIN first to redeem rewards.');
                }
                if ((int) $reward['quantity_available'] <= 0) {
                    throw new RuntimeException('Sorry, that reward is out of stock.');
                }
                if ($balance < (int) $reward['points_cost']) {
                    throw new RuntimeException('You need ' . num($reward['points_cost']) . ' points to redeem this.');
                }

                // Generate unique redemption code
                do {
                    $code = generateRedemptionCode();
                    $chk = $pdo->prepare('SELECT 1 FROM redemptions WHERE redemption_code = ?');
                    $chk->execute([$code]);
                } while ($chk->fetchColumn());

                $pointsCost = (int) $reward['points_cost'];
                $pdo->prepare('INSERT INTO redemptions (user_id, reward_id, redemption_code, points_spent) VALUES (?, ?, ?, ?)')
                    ->execute([$user['id'], $rewardId, $code, $pointsCost]);

                $transaction = createRedemptionTransaction(
                    (int) $user['id'],
                    $pointsCost,
                    $reward['title']
                );
                $pdo->prepare('UPDATE rewards SET quantity_available = quantity_available - 1 WHERE id = ?')
                    ->execute([$rewardId]);

                $pdo->commit();
                try {
                    sendWalletNotification((int) $user['id'], 'redemption', [
                        'amount' => $pointsCost,
                        'reward' => $reward['title'],
                        'code' => $code,
                        'new_balance' => $transaction['balance_after'],
                    ]);
                } catch (Throwable $notificationError) {
                    error_log('Reward notification failed for user ' . (int) $user['id'] . ': ' . $notificationError->getMessage());
                }
                setFlash('success', 'Redeemed! Your code is ' . $code . ' — show it at ' . $reward['title'] . '. See it any time on your profile.');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($verificationRequired) {
                    $errors[] = 'Verify your email address before redeeming rewards.';
                } else {
                    error_log('Reward redemption failed for user ' . (int) $user['id'] . ': ' . $ex->getMessage());
                    setFlash('error', 'The redemption could not be completed. Please try again.');
                }
            }
        }

        if (!$errors) {
            header('Location: rewards.php');
            exit;
        }
    }
}

// Build the catalogue query with an optional category filter.
$sql = 'SELECT w.*, p.business_name, p.category AS partner_category
          FROM rewards w JOIN partners p ON p.id = w.partner_id';
$params = [];
$categories = ['discount' => 'Discounts', 'eco-product' => 'Eco Products', 'donation' => 'Donations'];
if (isset($categories[$filter])) {
    $sql .= ' WHERE w.category = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY w.points_cost ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rewards = $stmt->fetchAll();

// Always fetch fresh balance from database to ensure up-to-date display after redemptions
$balance = 0;
if ($user) {
    $balStmt = db()->prepare('SELECT points_balance FROM users WHERE id = ?');
    $balStmt->execute([$user['id']]);
    $balance = (int) ($balStmt->fetchColumn() ?? 0);
}

$pageTitle = 'Rewards marketplace';
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-6xl mx-auto px-4 py-10">
    <!-- Error Messages -->
    <?php if ($errors): ?>
        <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm" role="alert">
            <ul class="space-y-1">
                <?php foreach ($errors as $err): ?><li>• <?= e($err) ?></li><?php endforeach; ?>
            </ul>
            <?php if ($verificationRequired): ?>
                <a href="resend_verification.php" class="mt-2 inline-block font-bold underline">Resend verification email</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="eco-kicker">Rewards</span>
            <h1 class="mt-4 text-2xl font-extrabold text-slate-900">Rewards marketplace 🎁</h1>
            <p class="mt-1 text-slate-600">Turn your EcoPoints into real local rewards and green donations.</p>
            <?php if (EMAIL_FEATURES_ENABLED && $user && !$emailVerified): ?>
                <p class="mt-2 text-sm font-semibold text-amber-800" role="status">
                    Verify your email address before redeeming rewards.
                    <a href="resend_verification.php" class="underline">Resend verification email</a>
                </p>
            <?php endif; ?>
        </div>
        <?php if ($user): ?>
            <div class="eco-panel px-5 py-3 text-center">
                <div class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Your balance</div>
                <div class="text-2xl font-extrabold text-eco-700"><?= num($balance) ?> pts</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Category filter -->
    <div class="mt-6 flex flex-wrap gap-2">
        <?php
        $tabs = ['all' => 'All'] + $categories;
        foreach ($tabs as $key => $label):
            $isActive = $filter === $key; ?>
            <a href="?category=<?= e($key) ?>"
               class="px-4 py-2 rounded-full text-sm font-semibold transition <?= e($isActive ? 'bg-eco-600 text-white' : 'bg-white border border-eco-100 text-slate-600 hover:bg-eco-50') ?>">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Reward cards -->
    <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($rewards as $reward):
            $affordable = $user && $emailVerified && $balance >= (int) $reward['points_cost'];
            $inStock    = (int) $reward['quantity_available'] > 0; ?>
            <div class="reward-card p-6 flex flex-col">
                <div class="flex items-start justify-between">
                    <span class="w-14 h-14 rounded-2xl bg-eco-50 grid place-items-center text-3xl" aria-hidden="true"><?= e($reward['icon']) ?></span>
                    <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-eco-100 text-eco-800 capitalize"><?= e(str_replace('-', ' ', $reward['category'])) ?></span>
                </div>
                <h2 class="mt-4 font-bold text-slate-900"><?= e($reward['title']) ?></h2>
                <p class="text-xs font-semibold text-slate-400 mt-0.5"><?= e($reward['business_name']) ?></p>
                <p class="mt-2 text-sm text-slate-600 flex-1"><?= e($reward['description']) ?></p>

                <div class="mt-4 flex items-center justify-between">
                    <span class="text-lg font-extrabold text-eco-700"><?= num($reward['points_cost']) ?> <span class="text-xs font-semibold text-slate-400">pts</span></span>
                    <?php if ($reward['expiry_date']): ?>
                        <span class="text-xs text-slate-400">Exp. <?= e(date('M Y', strtotime($reward['expiry_date']))) ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!$user): ?>
                    <a href="login.php" class="mt-4 w-full text-center py-2.5 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition">Log in to redeem</a>
                        <?php elseif (!$inStock): ?>
                    <button disabled class="mt-4 w-full py-2.5 rounded-xl bg-slate-100 text-slate-400 font-bold cursor-not-allowed">Out of stock</button>
                <?php else: ?>
                    <form method="post" class="mt-4" onsubmit="return confirm('Redeem &quot;<?= e(addslashes($reward['title'])) ?>&quot; for <?= num($reward['points_cost']) ?> points?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="initiate_redemption">
                        <input type="hidden" name="reward_id" value="<?= (int) $reward['id'] ?>">
                        <button type="submit" <?= e($affordable ? '' : 'disabled') ?>
                            class="w-full py-2.5 rounded-xl font-bold transition <?= e($affordable ? 'bg-eco-600 text-white hover:bg-eco-700' : 'bg-slate-100 text-slate-400 cursor-not-allowed') ?>">
                            <?= e(!$emailVerified ? 'Verify email to redeem' : ($affordable ? 'Redeem' : 'Need ' . num((int) $reward['points_cost'] - $balance) . ' more pts')) ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$rewards): ?>
        <p class="mt-10 text-center text-slate-500">No rewards in this category yet.</p>
    <?php endif; ?>
</section>

<!-- PIN Verification Modal for Redemption -->
<dialog id="redemptionPinModal" class="rounded-2xl shadow-2xl backdrop:bg-black/50 p-0 max-w-md">
    <?php if ($pendingRedemption): ?>
        <form method="post" class="p-8">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="confirm_redemption">
            <input type="hidden" name="reward_id" value="<?= (int) $pendingRedemption['reward_id'] ?>">
            
            <h2 class="text-2xl font-bold text-slate-900 mb-2">🔐 Confirm Redemption</h2>
            <p class="text-slate-600 text-sm mb-6">
                Enter your 4-6 digit PIN to confirm redeeming <strong><?= e($pendingRedemption['reward']['title']) ?></strong> for <strong><?= num((int) $pendingRedemption['reward']['points_cost']) ?> points</strong>.
            </p>

            <div class="mb-6">
                <label for="redeemPin" class="block text-sm font-semibold text-slate-700 mb-2">Wallet PIN</label>
                <input type="password" id="redeemPin" name="pin_attempt" maxlength="6" inputmode="numeric" placeholder="••••" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 text-center text-2xl tracking-widest" required autofocus>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg transition">
                    Confirm
                </button>
                <button type="button" onclick="document.getElementById('redemptionPinModal').close()" class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold py-3 rounded-lg transition">
                    Cancel
                </button>
            </div>
        </form>
    <?php endif; ?>
</dialog>

<script>
    // Show PIN modal if showing PIN form
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($showPinModal && $pendingRedemption): ?>
            document.getElementById('redemptionPinModal').showModal();
        <?php endif; ?>
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
